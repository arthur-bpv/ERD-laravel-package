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
        //
        // Obs: este boot() roda ANTES do middleware TrustProxies, então
        // getHost() ainda pode ver o host interno. Por isso checamos também o
        // header bruto X-Forwarded-Host, que o proxy sempre envia.
        $forwardedHost = (string) request()->server('HTTP_X_FORWARDED_HOST', '');
        $host = (string) request()->getHost();

        if (request()->server('HTTP_X_FORWARDED_HOST')
            || str_contains($host, 'cloudworkstations.dev')
            || str_contains($forwardedHost, 'cloudworkstations.dev')) {
            URL::forceScheme('https');
        }
    }
}
