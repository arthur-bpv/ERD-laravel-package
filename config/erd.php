<?php

// Development application: keep the existing database and URLs.
// Never publish this demo configuration to a production host application.
return [
    'middleware' => ['web'],
    'table' => 'diagrams',
    'development_assets' => env('ERD_DEVELOPMENT_ASSETS', true),
];
