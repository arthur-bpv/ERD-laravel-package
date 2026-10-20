<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Vite bundles Livewire assets, so disable Livewire's own injection.
        config(['livewire.inject_assets' => false]);

        // Cloud Workstations may hide X-Forwarded-Proto even with TrustProxies.
        // Force HTTPS to avoid mixed content and unreachable asset URLs.
        $host = (string) request()->getHost();

        if (request()->server('HTTP_X_FORWARDED_HOST')
            || str_contains($host, 'cloudworkstations.dev')) {
            URL::forceScheme('https');
        }
    }
}
