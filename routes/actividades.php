<?php

use App\Http\Controllers\Actividades\ActividadController;
use App\Http\Controllers\Actividades\CalificacionActividadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:capacitacion'])->group(function () {
    Route::prefix('actividades/{actividad}')->name('actividades.')->group(function () {
        Route::put('/', [ActividadController::class, 'update'])->name('update');
        Route::delete('/', [ActividadController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('calificaciones/actividades')->name('calificaciones.actividades.')->group(function () {
        Route::get('/', [CalificacionActividadController::class, 'index'])->name('index');
        Route::get('{entrega}', [CalificacionActividadController::class, 'show'])->name('show');
        Route::get('{entrega}/descargar', [CalificacionActividadController::class, 'descargar'])->name('descargar');
        Route::post('{entrega}/calificar', [CalificacionActividadController::class, 'calificar'])->name('calificar');
    });
});
