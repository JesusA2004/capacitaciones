<?php

use App\Http\Controllers\MiCapacitacion\CertificadoController;
use App\Http\Controllers\MiCapacitacion\EntregaActividadController;
use App\Http\Controllers\MiCapacitacion\IntentoCuestionarioController;
use App\Http\Controllers\MiCapacitacion\MiCapacitacionController;
use App\Http\Controllers\MiCapacitacion\ReproduccionController;
use App\Http\Controllers\MiCapacitacion\SesionEnVivoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:capacitacion'])
    ->prefix('mi-capacitacion')
    ->name('mi-capacitacion.')
    ->group(function () {
        Route::get('/', [MiCapacitacionController::class, 'index'])->name('index');
        Route::get('{curso}', [MiCapacitacionController::class, 'show'])->name('show');
        Route::post('lecciones/{leccion}/completar', [MiCapacitacionController::class, 'completarLeccion'])->name('lecciones.completar');

        Route::prefix('lecciones/{leccion}/reproduccion')->name('lecciones.reproduccion.')->group(function () {
            Route::post('iniciar', [ReproduccionController::class, 'iniciar'])->name('iniciar');
            Route::post('heartbeat', [ReproduccionController::class, 'heartbeat'])->name('heartbeat');

            Route::middleware('signed')->group(function () {
                Route::get('manifiesto/master.m3u8', [ReproduccionController::class, 'manifiestoMaestro'])->name('manifiesto-maestro');
                Route::get('manifiesto/{altura}.m3u8', [ReproduccionController::class, 'variante'])->name('variante');
                Route::get('segmento/{altura}/{archivo}', [ReproduccionController::class, 'segmento'])->name('segmento');
            });
        });

        Route::prefix('lecciones/{leccion}/cuestionario')->name('lecciones.cuestionario.')->group(function () {
            Route::get('/', [IntentoCuestionarioController::class, 'show'])->name('show');
            Route::post('iniciar', [IntentoCuestionarioController::class, 'iniciar'])->name('iniciar');
        });

        Route::post('intentos/{intento}/enviar', [IntentoCuestionarioController::class, 'enviar'])->name('intentos.enviar');

        Route::prefix('lecciones/{leccion}/actividad')->name('lecciones.actividad.')->group(function () {
            Route::get('/', [EntregaActividadController::class, 'show'])->name('show');
            Route::post('/', [EntregaActividadController::class, 'store'])->name('store');
        });

        Route::get('lecciones/{leccion}/sesion', [SesionEnVivoController::class, 'show'])->name('lecciones.sesion.show');

        Route::get('constancias/{certificado}/descargar', [CertificadoController::class, 'descargar'])->name('constancias.descargar');
    });
