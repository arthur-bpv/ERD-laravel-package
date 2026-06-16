<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        config(['livewire.inject_assets' => false]);

        // Firebase Studio / Cloud Workstations servem a app por um proxy HTTPS,
        // mas nem sempre repassam o X-Forwarded-Proto de forma detectável pelo
        // Laravel. Sem isto, o asset() gera URLs http:// e o navegador bloqueia
        // os assets do Vite como "mixed content" (CSS/JS não carregam).
        if (str_contains((string) request()->getHost(), 'cloudworkstations.dev')) {
            URL::forceScheme('https');
        }
    }
}
