<?php

declare(strict_types=1);

namespace ProPhoto\Access\Filament;

use Filament\Panel;
use ProPhoto\Access\Filament\Pages\PermissionMatrix;
use ProPhoto\Access\Filament\Resources\PermissionResource;
use ProPhoto\Access\Filament\Resources\RoleResource;
use ProPhoto\Contracts\Filament\RegistersFilament;

/**
 * Registers prophoto-access Filament resources with the central panel.
 *
 * Contributes:
 * - RoleResource (CRUD for roles)
 * - PermissionResource (CRUD for permissions)
 * - PermissionMatrix (interactive permission grid)
 */
class FilamentRegistrar implements RegistersFilament
{
    /**
     * Register access control resources with the Filament panel.
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

        // Register resources and pages directly
        $panel
            ->resources([
                RoleResource::class,
                PermissionResource::class,
            ])
            ->pages([
                PermissionMatrix::class,
            ]);

        return $panel;
    }
}
