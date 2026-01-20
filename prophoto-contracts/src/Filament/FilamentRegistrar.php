<?php

declare(strict_types=1);

namespace ProPhoto\Contracts\Filament;

use RuntimeException;

/**
 * Helper to discover Filament registrars across prophoto-* packages.
 *
 * Scans for conventional src/Filament/FilamentRegistrar.php files
 * and derives their fully-qualified class names via PSR-4 mapping.
 */
class FilamentRegistrar
{
    /**
     * Discover all Filament registrars in prophoto-* packages.
     *
     * @param  string  $rootDir  The monorepo root directory
     * @return array<string>  Array of fully-qualified class names implementing RegistersFilament
     */
    public static function discoverRegistrars(string $rootDir): array
    {
        $registrars = [];
        $pattern = $rootDir . '/prophoto-*/src/Filament/FilamentRegistrar.php';
        $registrarFiles = glob($pattern);

        if ($registrarFiles === false) {
            return [];
        }

        foreach ($registrarFiles as $registrarFile) {
            try {
                $className = self::deriveClassName($registrarFile);

                if ($className && class_exists($className)) {
                    if (is_subclass_of($className, RegistersFilament::class)) {
                        $registrars[] = $className;
                    } else {
                        error_log("Warning: {$className} does not implement RegistersFilament");
                    }
                } elseif ($className) {
                    error_log("Warning: Could not load class {$className} - skipping");
                }
            } catch (\Throwable $e) {
                error_log("Warning: Failed to process {$registrarFile}: {$e->getMessage()}");
                continue;
            }
        }

        // Sort alphabetically by package slug for deterministic ordering
        sort($registrars);

        return $registrars;
    }

    /**
     * Derive the fully-qualified class name from a registrar file path.
     *
     * @param  string  $filePath  Absolute path to FilamentRegistrar.php
     * @return string|null  Fully-qualified class name, or null on failure
     */
    private static function deriveClassName(string $filePath): ?string
    {
        // Extract package directory from file path
        // e.g., /path/to/prophoto-ingest/src/Filament/FilamentRegistrar.php
        $packageDir = dirname(dirname(dirname($filePath)));
        $composerPath = $packageDir . '/composer.json';

        if (!file_exists($composerPath)) {
            error_log("Warning: composer.json not found at {$composerPath}");
            return null;
        }

        $composerData = json_decode(file_get_contents($composerPath), true);

        if (!isset($composerData['autoload']['psr-4'])) {
            error_log("Warning: No PSR-4 autoload defined in {$composerPath}");
            return null;
        }

        // Find the PSR-4 namespace that maps to src/
        foreach ($composerData['autoload']['psr-4'] as $namespace => $path) {
            // Normalize path separators
            $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');

            if ($normalizedPath === 'src' || $normalizedPath === 'src/') {
                // Build the fully-qualified class name
                // Namespace should already have trailing backslash in composer.json
                $namespace = rtrim($namespace, '\\');
                return $namespace . '\\Filament\\FilamentRegistrar';
            }
        }

        error_log("Warning: Could not find PSR-4 mapping for src/ in {$composerPath}");
        return null;
    }
}
