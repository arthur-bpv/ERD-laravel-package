<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Hello, World!',
    ]);
});

// Página de verificação visual: Livewire + Alpine + AlpineFlow + Wireflow (CSS e JS)
Route::livewire('/flow-check', 'flow-health-check');
