<?php

declare(strict_types=1);

namespace ProPhoto\Debug\Filament;

use Filament\Panel;
use ProPhoto\Contracts\Filament\RegistersFilament;
use ProPhoto\Debug\Filament\Pages\ConfigSnapshotsPage;
use ProPhoto\Debug\Filament\Pages\IngestTracesPage;

/**
 * Registers prophoto-debug Filament pages with the central panel.
 *
 * IMPORTANT: Debug pages are GATED:
 * - Only in local/staging environments OR
 * - Only for users with 'prophoto.debug.access' permission
 *
 * Contributes:
 * - IngestTracesPage (view debug traces)
 * - ConfigSnapshotsPage (view config snapshots)
 */
class FilamentRegistrar implements RegistersFilament
{
    /**
     * Register debug pages with the Filament panel (with environment gating).
     *
     * @param  \Filament\Panel  $panel
     * @return \Filament\Panel
     */
    public static function register(Panel $panel): Panel
    {
        // Defensive check: ensure Filament is available
        if (!class_exists(Panel::class)) {
            return $panel;
        }

        // GATE 1: Environment check - only local/staging
        $allowedEnvironments = ['local', 'staging', 'testing'];
        $currentEnv = app()->environment();

        if (!in_array($currentEnv, $allowedEnvironments, true)) {
            // In production, require explicit permission
            if (!self::userHasDebugPermission()) {
                return $panel;
            }
        }

        // GATE 2: Config check - must be explicitly enabled
        if (!config('debug.filament.enabled', false)) {
            return $panel;
        }

        // Register debug pages
        $panel->pages([
            IngestTracesPage::class,
            ConfigSnapshotsPage::class,
        ]);

        return $panel;
    }

    /**
     * Check if the current user has debug access permission.
     *
     * @return bool
     */
    private static function userHasDebugPermission(): bool
    {
        // Check if auth is available
        if (!function_exists('auth') || !auth()->check()) {
            return false;
        }

        $user = auth()->user();

        // Check if Spatie Permission is available
        if (method_exists($user, 'can')) {
            return $user->can('prophoto.debug.access');
        }

        return false;
    }
}
