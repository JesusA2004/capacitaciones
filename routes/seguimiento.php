<?php

use App\Http\Controllers\Seguimiento\NineBoxController;
use App\Http\Controllers\Seguimiento\SeguimientoController;
use App\Http\Controllers\Seguimiento\VinculosReportesController;
use Illuminate\Support\Facades\Route;

// Seguimiento (Fase 2, docs/SEGUIMIENTO.md): datos duros de Reportes por
// sucursal y por gestor, por semana y por mes. Detrás de feature:seguimiento
// (config/features.php). Permisos seguimiento.ver / seguimiento.vincular
// (VinculoReportesPolicy); el alcance de sucursales lo resuelve
// AlcanceSeguimientoService (módulo «seguimiento» de Roles y permisos).
Route::middleware(['auth', 'verified', 'feature:seguimiento'])->prefix('seguimiento')->name('seguimiento.')->group(function () {
    // La entrada del módulo es la Nine Box (2026-11-17): los datos duros de
    // Reportes solo informan el desempeño. «Por sucursal» y «Por gestor»
    // siguen como consulta secundaria, sin entrada en el menú.
    Route::get('/', [SeguimientoController::class, 'inicio'])->name('index');
    Route::get('sucursales', [SeguimientoController::class, 'sucursales'])->name('sucursales.index');
    Route::get('gestores', [SeguimientoController::class, 'gestores'])->name('gestores.index');
    Route::get('gestores/{empleado}', [SeguimientoController::class, 'gestor'])->name('gestores.show')->whereNumber('empleado');

    // Nine Box (docs/NINE_BOX.md): desempeño (Reportes) × potencial
    // (evaluaciones técnicas + calibración). Además detrás de feature:nine_box;
    // permisos nine_box.* (NineBoxCalibracionPolicy).
    Route::prefix('nine-box')->name('nine-box.')->middleware('feature:nine_box')->group(function () {
        Route::get('/', [NineBoxController::class, 'index'])->name('index');
        Route::get('exportar', [NineBoxController::class, 'exportar'])->name('exportar')->middleware('throttle:20,1');
        Route::get('personas/{colaborador}', [NineBoxController::class, 'persona'])->name('personas.show')->whereNumber('colaborador');
        Route::post('calibraciones', [NineBoxController::class, 'calibrar'])->name('calibraciones.store')->middleware('throttle:60,1');
        Route::put('configuracion', [NineBoxController::class, 'configurar'])->name('configuracion.update')->middleware('throttle:20,1');
    });

    Route::prefix('vinculos')->name('vinculos.')->group(function () {
        Route::get('/', [VinculosReportesController::class, 'index'])->name('index');
        Route::post('sucursales', [VinculosReportesController::class, 'vincularSucursal'])->name('sucursales.store')->middleware('throttle:120,1');
        Route::post('colaboradores', [VinculosReportesController::class, 'vincularColaborador'])->name('colaboradores.store')->middleware('throttle:120,1');
        Route::get('colaboradores/buscar', [VinculosReportesController::class, 'buscarColaboradores'])->name('colaboradores.buscar')->middleware('throttle:240,1');
        Route::post('actualizar', [VinculosReportesController::class, 'actualizar'])->name('actualizar')->middleware('throttle:10,1');
        Route::post('confirmar-sugerencias', [VinculosReportesController::class, 'confirmarSugerencias'])->name('confirmar-sugerencias')->middleware('throttle:10,1');
        Route::delete('{vinculo}', [VinculosReportesController::class, 'desvincular'])->name('destroy')->whereNumber('vinculo')->middleware('throttle:120,1');
    });
});
