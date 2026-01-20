<?php

/**
 * ProPhoto Sandbox Application Configuration (Partial)
 *
 * This file contains additions to merge into the sandbox's config/app.php.
 * The prophoto.php script should merge these providers into the fresh Laravel installation.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Additional Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically registered with
    | the sandbox application. Add the ProPhotoPanelProvider to register the
    | central Filament panel.
    |
    */

    'providers' => [
        // ProPhoto Filament Panel Provider (owns the ONE panel)
        App\Providers\Filament\ProPhotoPanelProvider::class,
    ],
];
