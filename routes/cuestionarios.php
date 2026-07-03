<?php

use App\Http\Controllers\Cuestionarios\BancoPreguntaController;
use App\Http\Controllers\Cuestionarios\CalificacionCuestionarioController;
use App\Http\Controllers\Cuestionarios\CuestionarioController;
use App\Http\Controllers\Cuestionarios\PreguntaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('bancos-preguntas')->name('bancos-preguntas.')->group(function () {
        Route::get('/', [BancoPreguntaController::class, 'index'])->name('index');
        Route::post('/', [BancoPreguntaController::class, 'store'])->name('store');
        Route::get('{banco}', [BancoPreguntaController::class, 'show'])->name('show');
        Route::put('{banco}', [BancoPreguntaController::class, 'update'])->name('update');
        Route::delete('{banco}', [BancoPreguntaController::class, 'destroy'])->name('destroy');

        Route::post('{banco}/preguntas', [PreguntaController::class, 'store'])->name('preguntas.store');
        Route::put('{banco}/preguntas/{pregunta}', [PreguntaController::class, 'update'])->name('preguntas.update');
        Route::delete('{banco}/preguntas/{pregunta}', [PreguntaController::class, 'destroy'])->name('preguntas.destroy');
    });

    Route::prefix('cuestionarios/{cuestionario}')->name('cuestionarios.')->group(function () {
        Route::put('/', [CuestionarioController::class, 'update'])->name('update');
        Route::put('preguntas', [CuestionarioController::class, 'actualizarPreguntas'])->name('preguntas.actualizar');
        Route::delete('/', [CuestionarioController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('calificaciones/cuestionarios')->name('calificaciones.cuestionarios.')->group(function () {
        Route::get('/', [CalificacionCuestionarioController::class, 'index'])->name('index');
        Route::get('{intento}', [CalificacionCuestionarioController::class, 'show'])->name('show');
        Route::post('respuestas/{respuesta}', [CalificacionCuestionarioController::class, 'calificar'])->name('calificar');
        Route::get('respuestas/{respuesta}/descargar', [CalificacionCuestionarioController::class, 'descargar'])->name('respuestas.descargar');
    });
});
