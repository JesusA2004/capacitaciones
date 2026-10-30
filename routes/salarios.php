<?php

use App\Http\Controllers\Salarios\AdministracionSalariosController;
use Illuminate\Support\Facades\Route;

// Organización → Administración de salarios (permisos salarios.*, sin API: dato sensible).
Route::middleware(['auth', 'verified'])
    ->prefix('organizacion/salarios')
    ->name('salarios.')
    ->group(function () {
        Route::get('/', [AdministracionSalariosController::class, 'index'])->name('index');
        Route::put('tabulador/{puesto}', [AdministracionSalariosController::class, 'guardarTabulador'])->name('tabulador.guardar');
        Route::put('matriz', [AdministracionSalariosController::class, 'guardarMatriz'])->name('matriz.guardar');
        Route::get('exportar/excel', [AdministracionSalariosController::class, 'exportarExcel'])->name('exportar.excel');
        Route::get('exportar/pdf', [AdministracionSalariosController::class, 'exportarPdf'])->name('exportar.pdf');
        Route::get('importar/formato', [AdministracionSalariosController::class, 'formatoImportacion'])->name('importar.formato');
        Route::post('importar/analizar', [AdministracionSalariosController::class, 'analizarImportacion'])->name('importar.analizar');
        Route::post('importar/{importacion}/aplicar', [AdministracionSalariosController::class, 'aplicarImportacion'])->name('importar.aplicar');
    });
