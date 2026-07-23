<?php

use App\Http\Controllers\Multimedia\CargaResumibleController;
use App\Http\Controllers\Multimedia\RecursoMultimediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:capacitacion'])
    ->prefix('multimedia')
    ->name('multimedia.')
    ->group(function () {
        Route::get('/', [RecursoMultimediaController::class, 'index'])->name('index');
        Route::post('/', [RecursoMultimediaController::class, 'store'])->name('store');
        Route::get('{recurso}/estado', [RecursoMultimediaController::class, 'estado'])->name('estado');
        Route::delete('{recurso}', [RecursoMultimediaController::class, 'destroy'])->name('destroy');

        // Carga de video por bloques reanudable (Fase 9). Endpoints JSON,
        // no rutas Inertia: el frontend los consume con fetch/XHR.
        Route::prefix('cargas')->name('cargas.')->group(function () {
            Route::post('/', [CargaResumibleController::class, 'iniciar'])->name('iniciar');
            Route::get('{identificador}', [CargaResumibleController::class, 'estado'])->name('estado');
            Route::post('{identificador}/bloques', [CargaResumibleController::class, 'bloque'])->name('bloque');
            Route::post('{identificador}/pausar', [CargaResumibleController::class, 'pausar'])->name('pausar');
            Route::post('{identificador}/reanudar', [CargaResumibleController::class, 'reanudar'])->name('reanudar');
            Route::delete('{identificador}', [CargaResumibleController::class, 'cancelar'])->name('cancelar');
        });
    });
