<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ProPhoto\Contracts\Filament\FilamentRegistrar;

/**
 * ProPhoto Admin Panel Provider
 *
 * This is the ONE central Filament Panel for the ProPhoto monorepo.
 * Packages contribute resources/pages/widgets via FilamentRegistrar contract.
 *
 * Architecture:
 * - Sandbox OWNS the panel configuration
 * - Packages REGISTER their contributions via RegistersFilament interface
 * - Discovery is automatic via FilamentRegistrar::discoverRegistrars()
 */
class ProPhotoPanelProvider extends PanelProvider
{
    /**
     * Configure the ProPhoto admin panel.
     *
     * @param  Panel  $panel
     * @return Panel
     */
    public function panel(Panel $panel): Panel
    {
        // Base panel configuration
        $panel = $panel
            ->id('prophoto')
            ->path('prophoto')
            ->login()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->brandName('ProPhoto Admin')
            ->favicon(asset('favicon.ico'));

        // Discover and apply package registrars
        $panel = $this->registerPackageContributions($panel);

        return $panel;
    }

    /**
     * Discover and apply all package Filament registrars.
     *
     * Scans prophoto-* packages for FilamentRegistrar classes and applies them
     * in alphabetical order for deterministic behavior.
     *
     * @param  Panel  $panel
     * @return Panel
     */
    protected function registerPackageContributions(Panel $panel): Panel
    {
        $monorepoRoot = base_path('..');

        // Discover all registrars from prophoto-* packages
        $registrars = FilamentRegistrar::discoverRegistrars($monorepoRoot);

        // Apply each registrar in order
        foreach ($registrars as $registrarClass) {
            try {
                $panel = $registrarClass::register($panel);
            } catch (\Throwable $e) {
                // Log error but don't fail - allow panel to boot with partial registrations
                report($e);
                logger()->error("Failed to register Filament contributions from {$registrarClass}: {$e->getMessage()}");
            }
        }

        return $panel;
    }
}
