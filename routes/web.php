<?php
use App\Livewire\Copa; // Importa a nova classe Copa
use Illuminate\Support\Facades\Route;

// Configura o endpoint /copa2026 apontando para o seu novo componente renomeado
Route::get('/copa2026', Copa::class)->name('copa2026');