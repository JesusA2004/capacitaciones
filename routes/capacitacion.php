<?php

use App\Http\Controllers\Capacitacion\CertificacionController;
use App\Http\Controllers\Capacitacion\CertificacionFormatoController;
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

        // Certificación por formato oficial RH-CC-R-001 (CertificacionMrLanaPolicy).
        Route::middleware('feature:certificacion')
            ->prefix('certificacion/colaboradores/{colaborador}')
            ->name('certificacion.')
            ->group(function () {
                Route::get('formato', [CertificacionFormatoController::class, 'formato'])->name('formato');
                Route::post('firmado', [CertificacionFormatoController::class, 'subirFirmado'])->name('subir-firmado');
                Route::get('firmado', [CertificacionFormatoController::class, 'firmado'])->name('firmado');
                Route::get('certificado', [CertificacionFormatoController::class, 'certificado'])->name('certificado');
            });
    });
