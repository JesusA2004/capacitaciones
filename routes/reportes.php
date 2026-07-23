<?php

use App\Http\Controllers\Reportes\ExportacionCumplimientoController;
use App\Http\Controllers\Reportes\ReporteCumplimientoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:capacitacion'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('cumplimiento', [ReporteCumplimientoController::class, 'index'])->name('cumplimiento.index');
    Route::get('cumplimiento/exportar', [ExportacionCumplimientoController::class, 'exportar'])->name('cumplimiento.exportar');
});
