<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/schema');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Hello, World!',
    ]);
});

// Página de verificação visual: Livewire + Alpine + AlpineFlow + Wireflow (CSS e JS)
Route::livewire('/flow-check', 'flow-health-check');


Route::livewire('/board', 'Board');

// Modelador de esquema de banco (ERD estilo UML, relações em pé de galinha)
Route::livewire('/schema', App\Livewire\SchemaBoard::class);