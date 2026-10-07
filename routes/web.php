<?php

use App\Http\Controllers\ProcessController;
use App\Http\Controllers\TestProcessController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/procesos', [ProcessController::class, 'index'])->name('processes.index');
Route::post('/procesos/prueba', [TestProcessController::class, 'store'])->name('processes.test.store');
