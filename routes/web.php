<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CpuMemoryController;
use App\Http\Controllers\DeadlockController;
use App\Http\Controllers\DeadlockHistoryController;
use App\Http\Controllers\DeadlockRecoveryController;
use App\Http\Controllers\M6\DashboardController;
use App\Http\Controllers\M6\UserManagementController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\SchedulingController;
use App\Http\Controllers\SchedulingHistoryController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\TestProcessController;
use App\Http\Middleware\AuthenticateStorage;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::middleware('auth:web')->group(function () {
    Route::get('/', DashboardController::class);

    Route::get('/procesos', [ProcessController::class, 'index'])->name('processes.index');
    Route::get('/almacenamiento', [StorageController::class, 'index'])->middleware(AuthenticateStorage::class)->name('storage.index');
    Route::get('/interbloqueos', [DeadlockController::class, 'index'])->name('deadlocks.index');
    Route::post('/interbloqueos', [DeadlockController::class, 'validateScenario'])->middleware(EnsureUserIsAdmin::class)->name('deadlocks.validate');
    Route::post('/interbloqueos/solicitudes', [DeadlockController::class, 'evaluateRequest'])->middleware(EnsureUserIsAdmin::class)->name('deadlocks.request');
    Route::post('/interbloqueos/recuperacion', [DeadlockRecoveryController::class, 'simulate'])->middleware(EnsureUserIsAdmin::class)->name('deadlocks.recovery');
    Route::post('/interbloqueos/historial', [DeadlockHistoryController::class, 'store'])->middleware(EnsureUserIsAdmin::class)->name('deadlocks.history.store');
    Route::get('/interbloqueos/historial', [DeadlockHistoryController::class, 'index'])->name('deadlocks.history.index');
    Route::get('/interbloqueos/historial/{id}', [DeadlockHistoryController::class, 'show'])->name('deadlocks.history.show');
    Route::get('/planificacion', [SchedulingController::class, 'index'])->name('scheduling.index');
    Route::post('/planificacion/manual', [SchedulingController::class, 'manual'])->middleware(EnsureUserIsAdmin::class)->name('scheduling.manual');
    Route::post('/planificacion/aleatoria', [SchedulingController::class, 'random'])->middleware(EnsureUserIsAdmin::class)->name('scheduling.random');
    Route::post('/planificacion/simular', [SchedulingController::class, 'simulate'])->middleware(EnsureUserIsAdmin::class)->name('scheduling.simulate');
    Route::post('/planificacion/comparar', [SchedulingController::class, 'compare'])->middleware(EnsureUserIsAdmin::class)->name('scheduling.compare');
    Route::get('/planificacion/historial', [SchedulingHistoryController::class, 'index'])->name('scheduling.history');
    Route::get('/planificacion/historial/{id}', [SchedulingHistoryController::class, 'show'])
        ->whereNumber('id')->name('scheduling.history.show');
    Route::get('/cpu-memoria', [CpuMemoryController::class, 'index'])->name('cpu-memory.index');
    Route::get('/cpu-memoria/metricas', [CpuMemoryController::class, 'metrics'])->name('cpu-memory.metrics');
    Route::post('/procesos/prueba', [TestProcessController::class, 'store'])->middleware(EnsureUserIsAdmin::class)->name('processes.test.store');
    Route::post('/procesos/prueba/{managedProcess}/signal', [TestProcessController::class, 'signal'])
        ->whereNumber('managedProcess')->middleware(EnsureUserIsAdmin::class)->name('processes.test.signal');
    Route::post('/procesos/prueba/{managedProcess}/priority', [TestProcessController::class, 'priority'])
        ->whereNumber('managedProcess')->middleware(EnsureUserIsAdmin::class)->name('processes.test.priority');

    Route::middleware(EnsureUserIsAdmin::class)->prefix('usuarios')->name('users.')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        Route::get('/crear', [UserManagementController::class, 'create'])->name('create');
        Route::post('/', [UserManagementController::class, 'store'])->name('store');
        Route::get('/{user}/editar', [UserManagementController::class, 'edit'])->whereNumber('user')->name('edit');
        Route::patch('/{user}', [UserManagementController::class, 'update'])->whereNumber('user')->name('update');
        Route::patch('/{user}/rol', [UserManagementController::class, 'updateRole'])->whereNumber('user')->name('role');
        Route::get('/{user}/contrasena', [UserManagementController::class, 'editPassword'])->whereNumber('user')->name('password.edit');
        Route::put('/{user}/contrasena', [UserManagementController::class, 'updatePassword'])->whereNumber('user')->name('password.update');
    });
});
