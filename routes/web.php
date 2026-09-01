<?php

use App\Livewire\ProjectDashboard;
use App\Livewire\RelationalBoard;
use App\Livewire\SchemaBoard;
use Illuminate\Support\Facades\Route;

Route::livewire('/', ProjectDashboard::class)->name('dashboard');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Hello, World!',
    ]);
});

// Modelador de esquema de banco (ERD estilo UML, relações em pé de galinha)
Route::livewire('/schema', SchemaBoard::class)->name('schema.demo');
Route::livewire('/boards/er/{diagram}', SchemaBoard::class)->name('boards.er');
Route::livewire('/boards/relational/{diagram}', RelationalBoard::class)->name('boards.relational');
