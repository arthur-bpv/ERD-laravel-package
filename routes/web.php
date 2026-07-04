<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/laragram', function () {
    return view('laragram');
});


Route::get('/javascript', function () {
    return view('Javascript');
}); 

Route::get('/bateria', function () {
    return view('bateria');
});