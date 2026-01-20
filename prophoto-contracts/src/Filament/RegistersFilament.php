<?php

declare(strict_types=1);

namespace ProPhoto\Contracts\Filament;

/**
 * Contract for packages to register Filament resources with the central Panel.
 *
 * Implementing classes should provide a static register() method that accepts
 * a Filament Panel and returns it after registering resources, pages, widgets,
 * navigation groups, plugins, or other Filament contributions.
 *
 * This pattern allows sandbox to own the ONE Filament Panel while packages
 * contribute their admin UI components in a modular, centrally-orchestrated way.
 */
interface RegistersFilament
{
    /**
     * Register Filament resources, pages, widgets, etc. with the given panel.
     *
     * @param  \Filament\Panel  $panel
     * @return \Filament\Panel
     */
    public static function register(\Filament\Panel $panel): \Filament\Panel;
}
