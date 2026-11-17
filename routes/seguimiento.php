<?php

use App\Http\Controllers\Seguimiento\SeguimientoController;
use App\Http\Controllers\Seguimiento\VinculosReportesController;
use Illuminate\Support\Facades\Route;

// Seguimiento (Fase 2, docs/SEGUIMIENTO.md): datos duros de Reportes por
// sucursal y por gestor, por semana y por mes. Detrás de feature:seguimiento
// (config/features.php). Permisos seguimiento.ver / seguimiento.vincular
// (VinculoReportesPolicy); el alcance de sucursales lo resuelve
// AlcanceSeguimientoService (módulo «seguimiento» de Roles y permisos).
Route::middleware(['auth', 'verified', 'feature:seguimiento'])->prefix('seguimiento')->name('seguimiento.')->group(function () {
    Route::get('/', [SeguimientoController::class, 'sucursales'])->name('index');
    Route::get('gestores', [SeguimientoController::class, 'gestores'])->name('gestores.index');
    Route::get('gestores/{empleado}', [SeguimientoController::class, 'gestor'])->name('gestores.show')->whereNumber('empleado');

    Route::prefix('vinculos')->name('vinculos.')->group(function () {
        Route::get('/', [VinculosReportesController::class, 'index'])->name('index');
        Route::post('sucursales', [VinculosReportesController::class, 'vincularSucursal'])->name('sucursales.store')->middleware('throttle:120,1');
        Route::post('colaboradores', [VinculosReportesController::class, 'vincularColaborador'])->name('colaboradores.store')->middleware('throttle:120,1');
        Route::post('confirmar-sugerencias', [VinculosReportesController::class, 'confirmarSugerencias'])->name('confirmar-sugerencias')->middleware('throttle:10,1');
        Route::delete('{vinculo}', [VinculosReportesController::class, 'desvincular'])->name('destroy')->whereNumber('vinculo')->middleware('throttle:120,1');
    });
});
