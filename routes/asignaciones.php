<?php

use App\Http\Controllers\Asignaciones\AsignacionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('asignaciones')
    ->name('asignaciones.')
    ->group(function () {
        Route::get('/', [AsignacionController::class, 'index'])->name('index');
        Route::get('nueva', [AsignacionController::class, 'create'])->name('create');
        Route::post('/', [AsignacionController::class, 'store'])->name('store');
        Route::post('previsualizar', [AsignacionController::class, 'previsualizar'])->name('previsualizar');
        Route::post('{asignacion}/cancelar', [AsignacionController::class, 'cancelar'])->name('cancelar');
    });
