<?php

use App\Http\Controllers\Reportes\ExportacionCumplimientoController;
use App\Http\Controllers\Reportes\ReporteCumplimientoController;
use App\Http\Controllers\Reportes\RotacionPersonalController;
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
// distinta. Las exportaciones son las de /rh/reportes (rh.reportes.excel/pdf),
// que es lo que usa la página: no se duplican aquí.
Route::middleware(['auth', 'verified'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/', [ReporteRhController::class, 'index'])->name('index');

    // Rotación de personal cargada desde el Excel de RH (no el KPI en vivo
    // del dashboard): subir → vista previa con hallazgos → confirmar.
    // Permisos rotacion.ver / rotacion.cargar (RotacionCargaPolicy).
    Route::prefix('rotacion')->name('rotacion.')->group(function () {
        Route::get('/', [RotacionPersonalController::class, 'index'])->name('index');
        Route::get('formato', [RotacionPersonalController::class, 'formato'])->name('formato');
        Route::post('cargas', [RotacionPersonalController::class, 'analizar'])->name('analizar')->middleware('throttle:10,1');
        Route::post('cargas/{carga}/aplicar', [RotacionPersonalController::class, 'aplicar'])->name('aplicar')->middleware('throttle:10,1');
        Route::post('cargas/{carga}/descartar', [RotacionPersonalController::class, 'descartar'])->name('descartar');
        Route::get('cargas/{carga}/archivo', [RotacionPersonalController::class, 'archivo'])->name('archivo');
    });
});

Route::middleware(['auth', 'verified', 'feature:capacitacion'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('cumplimiento', [ReporteCumplimientoController::class, 'index'])->name('cumplimiento.index');
    Route::get('cumplimiento/exportar', [ExportacionCumplimientoController::class, 'exportar'])->name('cumplimiento.exportar');
    Route::get('cumplimiento/exportar-pdf', [ExportacionCumplimientoController::class, 'exportarPdf'])->name('cumplimiento.exportar-pdf');
});
