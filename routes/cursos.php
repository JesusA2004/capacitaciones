<?php

use App\Http\Controllers\Actividades\ActividadController;
use App\Http\Controllers\Cuestionarios\CuestionarioController;
use App\Http\Controllers\Cursos\CursoController;
use App\Http\Controllers\Cursos\CursoModuloController;
use App\Http\Controllers\Cursos\LeccionController;
use App\Http\Controllers\Reuniones\SesionEnVivoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:capacitacion'])
    ->prefix('cursos')
    ->name('cursos.')
    ->group(function () {
        Route::get('/', [CursoController::class, 'index'])->name('index');
        Route::post('/', [CursoController::class, 'store'])->name('store');
        Route::get('{curso}', [CursoController::class, 'edit'])->name('edit');
        Route::put('{curso}', [CursoController::class, 'update'])->name('update');
        Route::delete('{curso}', [CursoController::class, 'destroy'])->name('destroy');
        Route::post('{curso}/publicar', [CursoController::class, 'publicar'])->name('publicar');
        Route::post('{curso}/archivar', [CursoController::class, 'archivar'])->name('archivar');

        Route::post('{curso}/modulos', [CursoModuloController::class, 'store'])->name('modulos.store');
        Route::put('{curso}/modulos/{modulo}', [CursoModuloController::class, 'update'])->name('modulos.update');
        Route::delete('{curso}/modulos/{modulo}', [CursoModuloController::class, 'destroy'])->name('modulos.destroy');
        Route::post('{curso}/modulos/{modulo}/mover', [CursoModuloController::class, 'mover'])->name('modulos.mover');

        Route::post('{curso}/modulos/{modulo}/lecciones', [LeccionController::class, 'store'])->name('lecciones.store');
        Route::put('{curso}/modulos/{modulo}/lecciones/{leccion}', [LeccionController::class, 'update'])->name('lecciones.update');
        Route::delete('{curso}/modulos/{modulo}/lecciones/{leccion}', [LeccionController::class, 'destroy'])->name('lecciones.destroy');
        Route::post('{curso}/modulos/{modulo}/lecciones/{leccion}/mover', [LeccionController::class, 'mover'])->name('lecciones.mover');

        Route::get('{curso}/modulos/{modulo}/lecciones/{leccion}/cuestionario', [CuestionarioController::class, 'edit'])->name('lecciones.cuestionario.edit');
        Route::post('{curso}/modulos/{modulo}/lecciones/{leccion}/cuestionario', [CuestionarioController::class, 'store'])->name('lecciones.cuestionario.store');

        Route::get('{curso}/modulos/{modulo}/lecciones/{leccion}/actividad', [ActividadController::class, 'edit'])->name('lecciones.actividad.edit');
        Route::post('{curso}/modulos/{modulo}/lecciones/{leccion}/actividad', [ActividadController::class, 'store'])->name('lecciones.actividad.store');

        Route::get('{curso}/modulos/{modulo}/lecciones/{leccion}/sesion', [SesionEnVivoController::class, 'edit'])->name('lecciones.sesion.edit');
        Route::post('{curso}/modulos/{modulo}/lecciones/{leccion}/sesion', [SesionEnVivoController::class, 'store'])->name('lecciones.sesion.store');
    });
