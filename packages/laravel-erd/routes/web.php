<?php

use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
use Makermind\Erd4dev\Http\Controllers\AssetController;
use Makermind\Erd4dev\Http\Controllers\UpdateController;
use Makermind\Erd4dev\Livewire\ProjectDashboard;
use Makermind\Erd4dev\Livewire\RelationalBoard;
use Makermind\Erd4dev\Livewire\SchemaBoard;

Route::middleware(config('erd.middleware'))
    ->domain(config('erd.domain'))
    ->prefix('erd')
    ->name('erd.')
    ->group(function (): void {
        Route::get('/assets/{file}', [AssetController::class, 'show'])
            ->where('file', '.*')->name('assets');
        Route::post('/update', [UpdateController::class, '__invoke'])
            ->middleware(RequireLivewireHeaders::class)->name('update');
        Route::livewire('/', ProjectDashboard::class)->name('dashboard');
        Route::livewire('/schema', SchemaBoard::class)->name('schema.demo');
        Route::livewire('/boards/er/{diagram}', SchemaBoard::class)->name('boards.er');
        Route::livewire('/boards/relational/{diagram}', RelationalBoard::class)->name('boards.relational');
    });
