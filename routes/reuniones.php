<?php

use App\Http\Controllers\Reuniones\AsistenciaController;
use App\Http\Controllers\Reuniones\SesionEnVivoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('sesiones/{sesion}')->name('sesiones.')->group(function () {
    Route::put('/', [SesionEnVivoController::class, 'update'])->name('update');
    Route::delete('/', [SesionEnVivoController::class, 'destroy'])->name('destroy');

    Route::get('asistencias', [AsistenciaController::class, 'index'])->name('asistencias.index');
    Route::post('asistencias/{asistencia}', [AsistenciaController::class, 'marcar'])->name('asistencias.marcar');
});
