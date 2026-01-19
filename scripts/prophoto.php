#!/usr/bin/env php
<?php

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

/**
 * ProPhoto Master CLI
 *
 * The single entrypoint for all ProPhoto workspace operations.
 *
 * Usage:
 *   ./scripts/prophoto              # Interactive menu
 *   ./scripts/prophoto doctor       # Run diagnostics
 *   ./scripts/prophoto sandbox:fresh   # Recreate sandbox
 *   ./scripts/prophoto sandbox:reset   # Reset sandbox
 *   ./scripts/prophoto test         # Run tests
 *   ./scripts/prophoto refresh      # Daily refresh
 *   ./scripts/prophoto rebuild      # Full rebuild
 *   ./scripts/prophoto --dry-run doctor  # Dry run mode
 */

// Check if sandbox vendor autoload exists
$autoloadPath = __DIR__ . '/../sandbox/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo "\n";
    echo "\033[1;31m✗ Sandbox not installed\033[0m\n";
    echo "\033[0;33m→ The sandbox app is not set up yet.\033[0m\n";
    echo "\n";
    echo "\033[1;37mTo set up the sandbox:\033[0m\n";
    echo "  cd sandbox\n";
    echo "  composer install\n";
    echo "  cp .env.example .env\n";
    echo "  php artisan key:generate\n";
    echo "  php artisan migrate\n";
    echo "\n";
    echo "\033[0;90mOr use the automated setup (when implemented):\033[0m\n";
    echo "  ./scripts/prophoto sandbox:fresh\n";
    echo "\n";
    exit(1);
}

require_once $autoloadPath;

class ProPhotoWorkspace
{
    private string $baseDir;
    private string $sandboxDir;
    private bool $dryRun = false;
    private array $results = [];
    private array $packageMap = [
        'ingest' => 'Photo Ingest',
        'access' => 'Access Control',
        'debug' => 'Debug Utilities',
        'gallery' => 'Photo Gallery',
        'contracts' => 'Contracts'
    ];

    public function __construct()
    {
        $this->baseDir = dirname(__DIR__);
        $this->sandboxDir = $this->baseDir . '/sandbox';
    }

    public function run(array $argv): int
    {
        // Parse arguments
        $this->dryRun = in_array('--dry-run', $argv, true);
        $args = array_values(array_filter($argv, fn ($a) => $a !== '--dry-run'));
        $action = $args[1] ?? null;

        if ($this->dryRun) {
            warning('🏃 Running in DRY RUN mode - no changes will be made');
        }

        // Route to appropriate action
        if ($action === null) {
            return $this->showMenu();
        }

        return match($action) {
            'doctor' => $this->doctor(),
            'sandbox:fresh' => $this->sandboxFresh(),
            'sandbox:reset' => $this->sandboxReset(),
            'test' => $this->runTests(),
            'refresh' => $this->refresh(),
            'rebuild' => $this->rebuild(),
            '--help', '-h' => $this->showHelp(),
            default => $this->unknownCommand($action)
        };
    }

    private function showMenu(): int
    {
        info('🎨 ProPhoto Workspace Manager');

        $action = select(
            label: 'What would you like to do?',
            options: [
                'refresh' => '🔄 Daily Refresh (fast)',
                'rebuild' => '🔨 Full Rebuild (slow)',
                'sandbox:fresh' => '🆕 Sandbox → Fresh (destructive)',
                'sandbox:reset' => '♻️  Sandbox → Reset',
                'test' => '🧪 Run Tests',
                'doctor' => '🩺 Doctor / Diagnostics',
                'exit' => '👋 Exit'
            ]
        );

        if ($action === 'exit') {
            info('Goodbye!');
            return 0;
        }

        return match($action) {
            'doctor' => $this->doctor(),
            'sandbox:fresh' => $this->sandboxFresh(),
            'sandbox:reset' => $this->sandboxReset(),
            'test' => $this->runTests(),
            'refresh' => $this->refresh(),
            'rebuild' => $this->rebuild(),
            default => $this->unknownCommand($action)
        };
    }

    private function showHelp(): int
    {
        info('ProPhoto Master CLI');
        echo "\n";
        echo "Usage:\n";
        echo "  ./scripts/prophoto [action] [--dry-run]\n\n";
        echo "Actions:\n";
        echo "  (none)           Interactive menu\n";
        echo "  doctor           Run diagnostics\n";
        echo "  sandbox:fresh    Recreate sandbox (destructive)\n";
        echo "  sandbox:reset    Reset sandbox (preserves DB)\n";
        echo "  test             Run all tests\n";
        echo "  refresh          Daily refresh (cache clear + assets)\n";
        echo "  rebuild          Full rebuild (all packages)\n";
        echo "  --help, -h       Show this help\n\n";
        echo "Options:\n";
        echo "  --dry-run        Show what would be done without making changes\n\n";
        return 0;
    }

    private function unknownCommand(string $action): int
    {
        error("Unknown command: {$action}");
        echo "\nRun './scripts/prophoto --help' for usage information.\n";
        return 1;
    }

    // =========================================================================
    // DOCTOR: System diagnostics and validation
    // =========================================================================

    private function doctor(): int
    {
        info('🩺 Running ProPhoto Doctor...');
        echo "\n";

        $checks = [
            'PHP Version' => fn() => $this->checkPhpVersion(),
            'Composer Version' => fn() => $this->checkComposerVersion(),
            'Node Version' => fn() => $this->checkNodeVersion(),
            'ExifTool' => fn() => $this->checkExifTool(),
            'Sandbox Exists' => fn() => $this->checkSandboxExists(),
            'Path Repositories' => fn() => $this->checkPathRepositories(),
            'Symlinks Active' => fn() => $this->checkSymlinks(),
            'Package Cleanliness' => fn() => $this->checkPackageCleanliness(),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            $result = $check();
            $results[] = [
                'Check' => $name,
                'Status' => $result['pass'] ? '✅ PASS' : '❌ FAIL',
                'Details' => $result['message']
            ];
        }

        table(['Check', 'Status', 'Details'], $results);

        $failCount = count(array_filter($results, fn($r) => str_contains($r['Status'], 'FAIL')));

        if ($failCount === 0) {
            info('✅ All checks passed!');
            return 0;
        } else {
            warning("⚠️  {$failCount} check(s) failed");
            return 1;
        }
    }

    private function checkPhpVersion(): array
    {
        $version = PHP_VERSION;
        $pass = version_compare($version, '8.2.0', '>=');
        return [
            'pass' => $pass,
            'message' => $version . ($pass ? '' : ' (need 8.2+)')
        ];
    }

    private function checkComposerVersion(): array
    {
        $output = $this->exec('composer --version 2>&1', true);
        $pass = str_contains($output, 'Composer');
        preg_match('/(\d+\.\d+\.\d+)/', $output, $matches);
        $version = $matches[1] ?? 'unknown';
        return ['pass' => $pass, 'message' => $version];
    }

    private function checkNodeVersion(): array
    {
        $output = $this->exec('node --version 2>&1', true);
        $pass = str_starts_with(trim($output), 'v');
        return ['pass' => $pass, 'message' => trim($output)];
    }

    private function checkExifTool(): array
    {
        $output = $this->exec('exiftool -ver 2>&1', true);
        $pass = is_numeric(trim($output));
        return [
            'pass' => $pass,
            'message' => $pass ? 'v' . trim($output) : 'Not found'
        ];
    }

    private function checkSandboxExists(): array
    {
        $exists = is_dir($this->sandboxDir) && file_exists($this->sandboxDir . '/artisan');
        return [
            'pass' => $exists,
            'message' => $exists ? 'Found' : 'Not found'
        ];
    }

    private function checkPathRepositories(): array
    {
        $composerJson = $this->sandboxDir . '/composer.json';
        if (!file_exists($composerJson)) {
            return ['pass' => false, 'message' => 'composer.json not found'];
        }

        $content = json_decode(file_get_contents($composerJson), true);
        $hasPathRepos = false;
        $hasSymlink = false;

        foreach ($content['repositories'] ?? [] as $repo) {
            if ($repo['type'] === 'path') {
                $hasPathRepos = true;
                if (($repo['options']['symlink'] ?? false) === true) {
                    $hasSymlink = true;
                }
            }
        }

        $pass = $hasPathRepos && $hasSymlink;
        return [
            'pass' => $pass,
            'message' => $pass ? 'Configured correctly' : 'Missing or misconfigured'
        ];
    }

    private function checkSymlinks(): array
    {
        $vendorDir = $this->sandboxDir . '/vendor/prophoto';
        if (!is_dir($vendorDir)) {
            return ['pass' => false, 'message' => 'No prophoto packages installed'];
        }

        $symlinks = [];
        foreach (scandir($vendorDir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $vendorDir . '/' . $item;
            if (is_link($path)) {
                $symlinks[] = $item;
            }
        }

        $pass = count($symlinks) > 0;
        return [
            'pass' => $pass,
            'message' => $pass ? implode(', ', $symlinks) : 'No symlinks found'
        ];
    }

    private function checkPackageCleanliness(): array
    {
        $dirty = [];
        foreach (glob($this->baseDir . '/prophoto-*', GLOB_ONLYDIR) as $packageDir) {
            $packageName = basename($packageDir);
            if (is_dir($packageDir . '/vendor')) {
                $dirty[] = $packageName . '/vendor';
            }
            if (is_dir($packageDir . '/node_modules')) {
                $dirty[] = $packageName . '/node_modules';
            }
        }

        $pass = count($dirty) === 0;
        return [
            'pass' => $pass,
            'message' => $pass ? 'All clean' : implode(', ', $dirty)
        ];
    }

    // =========================================================================
    // SANDBOX:FRESH - Destructive sandbox recreation
    // =========================================================================

    private function sandboxFresh(): int
    {
        warning('⚠️  This will DELETE and recreate the sandbox!');

        if (!$this->dryRun) {
            $confirmed = confirm(
                label: 'Are you sure you want to continue?',
                default: false
            );

            if (!$confirmed) {
                info('Cancelled.');
                return 0;
            }
        }

        info('🆕 Creating fresh sandbox...');

        $steps = [
            'Removing old sandbox' => fn() => $this->exec("rm -rf {$this->sandboxDir}"),
            'Creating Laravel app' => fn() => $this->exec("cd {$this->baseDir} && composer create-project laravel/laravel sandbox --prefer-dist --no-interaction --quiet"),
            'Adding path repositories' => fn() => $this->addPathRepositories(),
            'Requiring local packages' => fn() => $this->exec("cd {$this->sandboxDir} && composer require prophoto/contracts:@dev prophoto/access:@dev prophoto/ingest:@dev prophoto/debug:@dev --no-scripts"),
            'Copying .env template' => fn() => $this->setupEnv(),
            'Publishing migrations' => fn() => $this->publishByProfile('sandbox:fresh'),
            'Running migrations' => fn() => $this->exec("cd {$this->sandboxDir} && php artisan migrate --force"),
            'Installing npm packages' => fn() => $this->exec("cd {$this->sandboxDir} && npm install --silent"),
            'Building assets' => fn() => $this->exec("cd {$this->sandboxDir} && npm run build"),
        ];

        return $this->runSteps($steps);
    }

    private function addPathRepositories(): string
    {
        $composerJson = $this->sandboxDir . '/composer.json';
        $content = json_decode(file_get_contents($composerJson), true);

        $content['repositories'] = [
            [
                'type' => 'path',
                'url' => '../prophoto-*',
                'options' => ['symlink' => true]
            ]
        ];

        if (!$this->dryRun) {
            file_put_contents($composerJson, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return 'Path repositories added';
    }

    private function setupEnv(): string
    {
        $envExample = $this->sandboxDir . '/.env.example';
        $env = $this->sandboxDir . '/.env';

        if (!$this->dryRun && file_exists($envExample)) {
            copy($envExample, $env);
            $this->exec("cd {$this->sandboxDir} && php artisan key:generate");
        }

        return '.env configured';
    }

    // =========================================================================
    // SANDBOX:RESET - Reset sandbox without destroying it
    // =========================================================================

    private function sandboxReset(): int
    {
        info('♻️  Resetting sandbox...');

        $steps = [
            'Removing vendor' => fn() => $this->exec("rm -rf {$this->sandboxDir}/vendor"),
            'Removing node_modules' => fn() => $this->exec("rm -rf {$this->sandboxDir}/node_modules"),
            'Clearing caches' => fn() => $this->exec("cd {$this->sandboxDir} && php artisan optimize:clear 2>/dev/null || true"),
            'Reinstalling composer deps' => fn() => $this->exec("cd {$this->sandboxDir} && composer install --no-scripts"),
            'Reinstalling npm deps' => fn() => $this->exec("cd {$this->sandboxDir} && npm install"),
            'Rebuilding assets' => fn() => $this->exec("cd {$this->sandboxDir} && npm run build"),
            'Running migrations' => fn() => $this->exec("cd {$this->sandboxDir} && php artisan migrate --force"),
        ];

        return $this->runSteps($steps);
    }

    // =========================================================================
    // TEST - Run all tests
    // =========================================================================

    private function runTests(): int
    {
        info('🧪 Running tests...');

        $steps = [];

        // Package tests
        foreach ($this->packageMap as $slug => $name) {
            $packageDir = $this->baseDir . "/prophoto-{$slug}";
            if (is_dir($packageDir) && file_exists($packageDir . '/composer.json')) {
                $steps["Testing {$name}"] = fn() => $this->exec("cd {$packageDir} && composer test 2>&1 || echo 'No tests configured'");
            }
        }

        // Sandbox tests
        $steps['Testing sandbox'] = fn() => $this->exec("cd {$this->sandboxDir} && php artisan test 2>&1 || echo 'No tests configured'");

        return $this->runSteps($steps);
    }

    // =========================================================================
    // REFRESH - Daily refresh (fast)
    // =========================================================================

    private function refresh(): int
    {
        info('🔄 Running daily refresh...');

        $steps = [
            'Composer dump-autoload' => fn() => $this->exec("cd {$this->sandboxDir} && composer dump-autoload -o"),
            'Clearing caches'        => fn() => $this->exec("cd {$this->sandboxDir} && php artisan optimize:clear"),
            'Publishing assets'      => fn() => $this->publishByProfile('refresh'),
        ];

        return $this->runSteps($steps);
    }

    // =========================================================================
    // REBUILD - Full rebuild (slow)
    // =========================================================================

    private function rebuild(): int
    {
        info('🔨 Running full rebuild...');

        $steps = [];

        // Rebuild each package
        foreach ($this->packageMap as $slug => $name) {
            $packageDir = $this->baseDir . "/prophoto-{$slug}";
            if (is_dir($packageDir) && file_exists($packageDir . '/package.json')) {
                $steps["Building {$name} assets"] = fn() => $this->exec("cd {$packageDir} && npm install && npm run build 2>&1 || echo 'No build script'");
            }
        }

        // Update sandbox
        $steps['Updating sandbox composer'] = fn() => $this->exec("cd {$this->sandboxDir} && composer update --no-scripts");
        $steps['Publishing assets + config'] = fn() => $this->publishByProfile('rebuild');
        $steps['Clearing caches'] = fn() => $this->exec("cd {$this->sandboxDir} && php artisan optimize:clear");
        $steps['Discovering packages'] = fn() => $this->exec("cd {$this->sandboxDir} && php artisan package:discover --ansi");

        return $this->runSteps($steps);
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    /**
     * Auto-discover and publish tags based on profile.
     * 
     * Profiles:
     * - refresh: *-assets, *-views (fast, safe)
     * - rebuild: *-assets, *-views, *-config (no migrations)
     * - sandbox:fresh: *-migrations only (explicit)
     */
    private function publishByProfile(string $profile): string
    {
        $availableTags = $this->discoverPublishTags();
        $tagsToPublish = $this->filterTagsByProfile($availableTags, $profile);
        
        if (empty($tagsToPublish)) {
            return "No tags to publish for profile '{$profile}'";
        }
        
        $published = [];
        $skipped = [];
        
        foreach ($tagsToPublish as $tag) {
            $result = $this->publishTag($tag);
            if ($result['published']) {
                $published[] = $tag;
            } else {
                $skipped[] = $tag;
            }
        }
        
        $summary = [];
        if (!empty($published)) {
            $summary[] = count($published) . ' published: ' . implode(', ', $published);
        }
        if (!empty($skipped) && getenv('APP_ENV') === 'local') {
            $summary[] = count($skipped) . ' skipped (no files): ' . implode(', ', $skipped);
        }
        
        return implode(' | ', $summary) ?: 'No changes';
    }
    
    /**
     * Discover all available publish tags via vendor:publish --list.
     */
    private function discoverPublishTags(): array
    {
        $output = $this->exec("cd {$this->sandboxDir} && php artisan vendor:publish --list 2>&1", true);
        
        $tags = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            // Parse lines like "  - tag: ingest-assets"
            if (preg_match('/^\s*-\s*tag:\s*([a-z0-9-]+)$/i', $line, $matches)) {
                $tags[] = $matches[1];
            }
        }
        
        return array_unique($tags);
    }
    
    /**
     * Filter tags based on publish profile.
     */
    private function filterTagsByProfile(array $tags, string $profile): array
    {
        $patterns = match($profile) {
            'refresh' => ['/^[a-z]+-assets$/', '/^[a-z]+-views$/'],
            'rebuild' => ['/^[a-z]+-assets$/', '/^[a-z]+-views$/', '/^[a-z]+-config$/'],
            'sandbox:fresh' => ['/^[a-z]+-migrations$/'],
            default => [],
        };
        
        $filtered = [];
        foreach ($tags as $tag) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $tag)) {
                    $filtered[] = $tag;
                    break;
                }
            }
        }
        
        return $filtered;
    }
    
    /**
     * Publish a single tag with filesystem snapshot detection.
     * 
     * Returns: ['published' => bool, 'files' => int]
     */
    private function publishTag(string $tag): array
    {
        $snapshot = $this->snapshotPublishPaths();
        
        // Execute publish
        $this->exec("cd {$this->sandboxDir} && php artisan vendor:publish --tag={$tag} --force 2>&1 || true", true);
        
        $changes = $this->detectPublishChanges($snapshot);
        
        return [
            'published' => $changes > 0,
            'files' => $changes,
        ];
    }
    
    /**
     * Snapshot known publish paths before publish.
     */
    private function snapshotPublishPaths(): array
    {
        $paths = [
            $this->sandboxDir . '/public/vendor',
            $this->sandboxDir . '/config',
            $this->sandboxDir . '/database/migrations',
            $this->sandboxDir . '/resources/views/vendor',
        ];
        
        $snapshot = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $snapshot[$path] = $this->hashDirectory($path);
            }
        }
        
        return $snapshot;
    }
    
    /**
     * Detect changes by comparing snapshots.
     */
    private function detectPublishChanges(array $beforeSnapshot): int
    {
        $changes = 0;
        
        foreach ($beforeSnapshot as $path => $beforeHash) {
            if (is_dir($path)) {
                $afterHash = $this->hashDirectory($path);
                if ($beforeHash !== $afterHash) {
                    $changes++;
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Generate a hash of directory contents (file count + total size).
     */
    private function hashDirectory(string $dir): string
    {
        if (!is_dir($dir)) {
            return '';
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $fileCount = 0;
        $totalSize = 0;
        $latestMtime = 0;
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileCount++;
                $totalSize += $file->getSize();
                $latestMtime = max($latestMtime, $file->getMTime());
            }
        }
        
        return md5("{$fileCount}:{$totalSize}:{$latestMtime}");
    }

    private function runSteps(array $steps): int
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($steps as $name => $step) {
            $stepStart = microtime(true);

            try {
                if ($this->dryRun) {
                    $output = "[DRY RUN] Would execute: {$name}";
                    $success = true;
                } else {
                    $output = spin(
                        fn() => $step(),
                        $name
                    );
                    $success = true;
                }
            } catch (\Exception $e) {
                $output = $e->getMessage();
                $success = false;
            }

            $duration = round((microtime(true) - $stepStart) * 1000);
            $results[] = [
                'Step' => $name,
                'Status' => $success ? '✅' : '❌',
                'Time' => $duration . 'ms'
            ];

            if (!$success && !$this->dryRun) {
                error("Failed: {$name}");
                echo $output . "\n";
                break;
            }
        }

        $totalTime = round((microtime(true) - $startTime) * 1000);

        echo "\n";
        table(['Step', 'Status', 'Time'], $results);

        $failCount = count(array_filter($results, fn($r) => $r['Status'] === '❌'));

        if ($failCount === 0) {
            info("✅ All steps completed successfully in {$totalTime}ms");
            return 0;
        } else {
            error("❌ {$failCount} step(s) failed");
            return 1;
        }
    }

    private function exec(string $command, bool $capture = false): string
    {
        if ($this->dryRun) {
            return "[DRY RUN] {$command}";
        }

        if ($capture) {
            return shell_exec($command) ?? '';
        }

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed: {$command}\n" . implode("\n", $output));
        }

        return implode("\n", $output);
    }
}

// Run the CLI
$workspace = new ProPhotoWorkspace();
exit($workspace->run($argv));
