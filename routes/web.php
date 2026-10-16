<?php

use App\Http\Controllers\CpuMemoryController;
use App\Http\Controllers\DeadlockController;
use App\Http\Controllers\DeadlockRecoveryController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SchedulingHistoryController;
use App\Http\Controllers\TestProcessController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/procesos', [ProcessController::class, 'index'])->name('processes.index');
Route::get('/interbloqueos', [DeadlockController::class, 'index'])->name('deadlocks.index');
Route::post('/interbloqueos', [DeadlockController::class, 'validateScenario'])->name('deadlocks.validate');
Route::post('/interbloqueos/solicitudes', [DeadlockController::class, 'evaluateRequest'])->name('deadlocks.request');
Route::post('/interbloqueos/recuperacion', [DeadlockRecoveryController::class, 'simulate'])->name('deadlocks.recovery');
Route::get('/planificacion', [SchedulingController::class, 'index'])->name('scheduling.index');
Route::post('/planificacion/manual', [SchedulingController::class, 'manual'])->name('scheduling.manual');
Route::post('/planificacion/aleatoria', [SchedulingController::class, 'random'])->name('scheduling.random');
Route::post('/planificacion/simular', [SchedulingController::class, 'simulate'])->name('scheduling.simulate');
Route::post('/planificacion/comparar', [SchedulingController::class, 'compare'])->name('scheduling.compare');
Route::get('/planificacion/historial', [SchedulingHistoryController::class, 'index'])->name('scheduling.history');
Route::get('/planificacion/historial/{id}', [SchedulingHistoryController::class, 'show'])
    ->whereNumber('id')->name('scheduling.history.show');
Route::get('/cpu-memoria', [CpuMemoryController::class, 'index'])->name('cpu-memory.index');
Route::get('/cpu-memoria/metricas', [CpuMemoryController::class, 'metrics'])->name('cpu-memory.metrics');
Route::post('/procesos/prueba', [TestProcessController::class, 'store'])->name('processes.test.store');
Route::post('/procesos/prueba/{managedProcess}/signal', [TestProcessController::class, 'signal'])
    ->whereNumber('managedProcess')->name('processes.test.signal');
Route::post('/procesos/prueba/{managedProcess}/priority', [TestProcessController::class, 'priority'])
    ->whereNumber('managedProcess')->name('processes.test.priority');
