<?php

use App\Http\Controllers\Reportes\ExportacionCumplimientoController;
use App\Http\Controllers\Reportes\ReporteCumplimientoController;
use App\Http\Controllers\Reportes\ReporteGeneralController;
use Illuminate\Support\Facades\Route;

// Hub de /reportes: NO está detrás de feature:capacitacion (a diferencia del
// grupo de abajo) porque cruza datos de los módulos de RH que sí están
// activos hoy (ver App\Http\Controllers\Reportes\ReporteGeneralController).
Route::middleware(['auth', 'verified'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/', [ReporteGeneralController::class, 'index'])->name('index');
    Route::get('exportar/excel', [ReporteGeneralController::class, 'exportarExcel'])->name('exportar.excel');
    Route::get('exportar/pdf', [ReporteGeneralController::class, 'exportarPdf'])->name('exportar.pdf');
});

Route::middleware(['auth', 'verified', 'feature:capacitacion'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('cumplimiento', [ReporteCumplimientoController::class, 'index'])->name('cumplimiento.index');
    Route::get('cumplimiento/exportar', [ExportacionCumplimientoController::class, 'exportar'])->name('cumplimiento.exportar');
    Route::get('cumplimiento/exportar-pdf', [ExportacionCumplimientoController::class, 'exportarPdf'])->name('cumplimiento.exportar-pdf');
});
