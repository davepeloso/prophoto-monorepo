#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ProPhoto Composer Audit (monorepo)
 *
 * Checks:
 * - php constraint is ^8.2
 * - PSR-4 prefix casing matches folder slug rules (ProPhoto\Xxx\)
 * - package name matches folder slug (prophoto-foo => prophoto/foo)
 * - baseline require: prophoto/contracts
 * - internal requires reference existing local packages
 * - AI vs Ai casing mismatch (namespace + folder + package name alignment)
 *
 * Run:
 *   php tools/audit-composer.php
 *   php tools/audit-composer.php --strict
 */

const BASELINE_PHP = '^8.2';

$strict = in_array('--strict', $argv, true);

$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "Unable to resolve repo root.\n");
    exit(2);
}

$packageDirs = glob($root . '/prophoto-*', GLOB_ONLYDIR) ?: [];
sort($packageDirs);

$errors = [];
$warnings = [];

/**
 * Helpers
 */
function readJson(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function slugFromDir(string $dir): string {
    // prophoto-ingest => ingest
    $base = basename($dir);
    return preg_replace('/^prophoto-/', '', $base) ?: $base;
}

function expectedPackageName(string $slug): string {
    return "prophoto/{$slug}";
}

function expectedPsr4Prefix(string $slug): string {
    // ingest => ProPhoto\Ingest\
    // ai => ProPhoto\AI\  (special-case if you want AI acronym)
    // By default: StudlyCase. But AI is the known gotcha.
    if ($slug === 'ai') return 'ProPhoto\\AI\\';
    return 'ProPhoto\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug))) . '\\';
}

function add(array &$bucket, string $pkg, string $msg): void {
    $bucket[] = "{$pkg}: {$msg}";
}

/**
 * Build local package map: prophoto/slug => dir
 */
$localPackages = []; // name => ['dir'=>..., 'slug'=>...]
foreach ($packageDirs as $dir) {
    $slug = slugFromDir($dir);
    $composerPath = $dir . '/composer.json';
    if (!file_exists($composerPath)) {
        add($warnings, basename($dir), "missing composer.json");
        continue;
    }

    $composer = readJson($composerPath);
    $name = $composer['name'] ?? '';
    if (!is_string($name) || $name === '') {
        // We'll still map by expected name so internal requires can resolve.
        $name = expectedPackageName($slug);
        add($warnings, basename($dir), "composer.json missing name; assuming {$name} for internal mapping");
    }

    $localPackages[$name] = ['dir' => $dir, 'slug' => $slug, 'composer' => $composer];
}

/**
 * Audit each package
 */
foreach ($localPackages as $pkgName => $meta) {
    $dir = $meta['dir'];
    $slug = $meta['slug'];
    $pkgLabel = basename($dir); // prophoto-ingest
    $composer = $meta['composer'];

    // 1) package name matches folder slug
    $expectedName = expectedPackageName($slug);
    if (($composer['name'] ?? null) !== $expectedName) {
        add($errors, $pkgLabel, "package name mismatch: composer name is '" . ($composer['name'] ?? '∅') . "', expected '{$expectedName}' for folder '{$pkgLabel}'");
    }

    // 2) php constraint
    $phpReq = $composer['require']['php'] ?? null;
    if (!is_string($phpReq) || trim($phpReq) !== BASELINE_PHP) {
        add($errors, $pkgLabel, "php constraint must be '" . BASELINE_PHP . "', found '" . (is_string($phpReq) ? $phpReq : '∅') . "'");
    }

    // 3) baseline require prophoto/contracts (allow contracts package itself to skip)
    if ($slug !== 'contracts') {
        $requires = $composer['require'] ?? [];
        if (!is_array($requires) || !array_key_exists('prophoto/contracts', $requires)) {
            add($errors, $pkgLabel, "missing baseline require: prophoto/contracts");
        }
    }

    // 4) PSR-4 prefix casing mismatch
    $psr4 = $composer['autoload']['psr-4'] ?? [];
    if (!is_array($psr4) || count($psr4) === 0) {
        add($warnings, $pkgLabel, "no autoload.psr-4 found");
    } else {
        $expectedPrefix = expectedPsr4Prefix($slug);
        // We consider it OK if expectedPrefix exists exactly.
        if (!array_key_exists($expectedPrefix, $psr4)) {
            // Detect near-miss by case-insensitive compare
            $foundKeys = array_keys($psr4);
            $ciMatch = null;
            foreach ($foundKeys as $k) {
                if (strcasecmp($k, $expectedPrefix) === 0) {
                    $ciMatch = $k;
                    break;
                }
            }
            if ($ciMatch !== null) {
                add($errors, $pkgLabel, "PSR-4 prefix casing mismatch: found '{$ciMatch}', expected '{$expectedPrefix}' (case-sensitive)");
            } else {
                add($errors, $pkgLabel, "PSR-4 prefix missing: expected '{$expectedPrefix}' in autoload.psr-4");
            }
        }

        // Also ensure PSR-4 path points to src/ (with Laravel-ish exceptions)
        foreach ($psr4 as $prefix => $path) {
            if (!is_string($path)) continue;

            $normalized = rtrim(str_replace('\\', '/', $path), '/') . '/';

            $isSeederPrefix = str_ends_with($prefix, 'Database\\Seeders\\');
            $isFactoryPrefix = str_ends_with($prefix, 'Database\\Factories\\');

            $allowed = ['src/'];
            if ($isSeederPrefix) $allowed[] = 'database/seeders/';
            if ($isFactoryPrefix) $allowed[] = 'database/factories/';

            if (!in_array($normalized, $allowed, true)) {
                add($warnings, $pkgLabel, "PSR-4 '{$prefix}' points to '{$path}' (expected one of: " . implode(', ', $allowed) . ")");
            }
        }
    }

    // 5) internal requires reference existing local packages
    $requires = $composer['require'] ?? [];
    if (is_array($requires)) {
        foreach ($requires as $dep => $ver) {
            if (!is_string($dep)) continue;
            if (!str_starts_with($dep, 'prophoto/')) continue;

            // allow laravel-ish or external prophoto packages if you want; for now CI wants local existence
            if (!array_key_exists($dep, $localPackages)) {
                add($errors, $pkgLabel, "internal require '{$dep}' does not exist as a local package folder");
            }
        }
    }

    // 6) AI vs Ai namespace mismatch (extra strict rule)
    // If folder is prophoto-ai, enforce composer name prophoto/ai AND PSR-4 prefix ProPhoto\AI\ (not Ai)
    if ($slug === 'ai') {
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        if (is_array($psr4)) {
            foreach (array_keys($psr4) as $prefix) {
                if (strcasecmp($prefix, 'ProPhoto\\AI\\') === 0 && $prefix !== 'ProPhoto\\AI\\') {
                    add($errors, $pkgLabel, "AI namespace casing mismatch: found '{$prefix}', expected 'ProPhoto\\\\AI\\\\'");
                }
                if ($prefix === 'ProPhoto\\Ai\\') {
                    add($errors, $pkgLabel, "AI namespace should be 'ProPhoto\\\\AI\\\\' (acronym), not 'ProPhoto\\\\Ai\\\\'");
                }
            }
        }
    }
}

/**
 * Output
 */
echo "ProPhoto Composer Audit\n";
echo "Root: {$root}\n";
echo "Packages: " . count($localPackages) . "\n\n";

if ($warnings) {
    echo "WARNINGS (" . count($warnings) . ")\n";
    foreach ($warnings as $w) echo "  - {$w}\n";
    echo "\n";
}

if ($errors) {
    echo "ERRORS (" . count($errors) . ")\n";
    foreach ($errors as $e) echo "  - {$e}\n";
    echo "\n";
    exit(1);
}

echo "OK: no errors found.\n";
exit(0);
