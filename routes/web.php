<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/schema');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Hello, World!',
    ]);
});

// Modelador de esquema de banco (ERD estilo UML, relações em pé de galinha)
Route::livewire('/schema', App\Livewire\SchemaBoard::class);