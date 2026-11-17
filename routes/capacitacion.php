<?php

use App\Http\Controllers\Capacitacion\CertificacionController;
use App\Http\Controllers\Capacitacion\PortadaCapacitacionController;
use Illuminate\Support\Facades\Route;

// Capacitación (Fase 3, docs/CAPACITACION.md): portada operativa y tablero de
// la «Certificación Mr. Lana». Con la bandera apagada, GET muestra la
// pantalla «Próximamente» en la misma URL (EnsureFeatureEnabled).
Route::middleware(['auth', 'verified', 'feature:capacitacion'])
    ->prefix('capacitacion')
    ->name('capacitacion.')
    ->group(function () {
        Route::get('/', [PortadaCapacitacionController::class, 'index'])->name('index');
        Route::get('certificacion', [CertificacionController::class, 'index'])
            ->middleware('feature:certificacion')
            ->name('certificacion');
    });
