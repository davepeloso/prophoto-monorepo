<?php

declare(strict_types=1);

namespace ProPhoto\Ingest\Filament;

use Filament\Panel;
use ProPhoto\Contracts\Filament\RegistersFilament;

/**
 * Registers prophoto-ingest Filament resources with the central panel.
 *
 * This package currently uses Inertia-based UI, but this registrar is ready
 * for future Filament admin pages (e.g., IngestSettings, IngestHistory).
 */
class FilamentRegistrar implements RegistersFilament
{
    /**
     * Register ingest resources with the Filament panel.
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

        // Future: Register IngestResource, IngestSettingsPage, etc.
        // For now, just establish the navigation group

        return $panel;
    }
}
