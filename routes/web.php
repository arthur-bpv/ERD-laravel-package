<?php
use App\Livewire\DiagramaBanco;
use App\Livewire\Organograma;
use Illuminate\Support\Facades\Route;

Route::get('/organograma', Organograma::class);

Route::get('/diagrama-banco', DiagramaBanco::class);

Route::get('/hello', function () {
    return 'Hello World';
});
