<?php

use App\Http\Controllers\Rh\ContratosController;
use App\Http\Controllers\Rh\PeriodosPruebaController;
use Illuminate\Support\Facades\Route;

/*
| Módulo «Contratos» (Etapa 2) y «Periodos por vencer» (Etapa 4): contratos
| que le corresponden a cada colaborador activo según su fecha de ingreso y
| periodos de prueba calculados por fecha de ingreso. La autorización real
| vive en RegularizacionContratosService / PeriodosPruebaService (permisos +
| alcance del módulo).
*/
Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.')->group(function () {
    Route::prefix('contratos')->name('contratos.')->group(function () {
        Route::get('/', [ContratosController::class, 'index'])->name('index');
        Route::post('generar-todos', [ContratosController::class, 'generarTodos'])->name('generar-todos')->middleware('throttle:5,1');
        Route::get('lotes/{lote}', [ContratosController::class, 'lote'])->name('lote')->whereNumber('lote');
        Route::post('{colaborador}/generar', [ContratosController::class, 'generar'])->name('generar')->whereNumber('colaborador')->middleware('throttle:30,1');
        Route::post('{colaborador}/completar-datos', [ContratosController::class, 'completarDatos'])->name('completar-datos')->whereNumber('colaborador');
        Route::get('{colaborador}/vista-previa/{clave}', [ContratosController::class, 'vistaPrevia'])->name('vista-previa')->whereNumber('colaborador')->where('clave', '[a-z_]+')->middleware('throttle:30,1');
    });

    Route::get('periodos-prueba', [PeriodosPruebaController::class, 'index'])->name('periodos-prueba.index');
});
