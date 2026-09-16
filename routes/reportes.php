<?php

use App\Http\Controllers\Reportes\ExportacionCumplimientoController;
use App\Http\Controllers\Reportes\ReporteCumplimientoController;
use App\Http\Controllers\Rh\ReporteRhController;
use Illuminate\Support\Facades\Route;

// Hub de /reportes: NO está detrás de feature:capacitacion (a diferencia del
// grupo de abajo) porque cruza datos de los módulos de RH que sí están
// activos hoy. Es el ÚNICO módulo de reportes visible en el sidebar — usa
// exactamente el mismo controlador/página/servicio que /rh/reportes
// (App\Http\Controllers\Rh\ReporteRhController + Rh/Reportes/Index.vue +
// App\Services\Reportes\ReportesRhService) en vez de duplicar la pantalla
// con un "hub" propio: antes existía un ReporteGeneralController que
// reusaba los componentes del Dashboard con polling cada 45s — eliminado
// porque era una segunda implementación del mismo reporte, no una vista
// distinta.
Route::middleware(['auth', 'verified'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/', [ReporteRhController::class, 'index'])->name('index');
    Route::get('exportar/excel', [ReporteRhController::class, 'exportarExcel'])->name('exportar.excel');
    Route::get('exportar/pdf', [ReporteRhController::class, 'exportarPdf'])->name('exportar.pdf');
});

Route::middleware(['auth', 'verified', 'feature:capacitacion'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('cumplimiento', [ReporteCumplimientoController::class, 'index'])->name('cumplimiento.index');
    Route::get('cumplimiento/exportar', [ExportacionCumplimientoController::class, 'exportar'])->name('cumplimiento.exportar');
    Route::get('cumplimiento/exportar-pdf', [ExportacionCumplimientoController::class, 'exportarPdf'])->name('cumplimiento.exportar-pdf');
});
