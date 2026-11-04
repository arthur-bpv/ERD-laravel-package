<?php

use Makermind\Erd4dev\Http\Middleware\AuthorizeErd;

return [
    'domain' => null,
    // Define Gate::define('erd.access', ...) in the host application.
    // This is a shared administrative workspace, not per-user storage.
    'middleware' => ['web', AuthorizeErd::class],
    'table' => 'erd_diagrams',
    'development_assets' => false,
];
