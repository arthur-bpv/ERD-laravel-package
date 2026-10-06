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
        // Os assets do Livewire vêm do bundle do Vite (app.js), então não deixamos
        // o Livewire injetar os dele (evita carregar duas vezes).
        config(['livewire.inject_assets' => false]);

        // Reforço de HTTPS: mesmo com TrustProxies, o Cloud Workstations nem sempre
        // repassa o X-Forwarded-Proto de forma detectável. Sem https, o navegador
        // bloqueia assets como "mixed content" e gera URLs http:// inalcançáveis.
        $host = (string) request()->getHost();

        if (request()->server('HTTP_X_FORWARDED_HOST')
            || str_contains($host, 'cloudworkstations.dev')) {
            URL::forceScheme('https');
        }
    }
}
