<?php

use App\Http\Controllers\Portal\MiProcesoController;
use App\Http\Controllers\Rh\CicloColaboradorController;
use App\Http\Controllers\Rh\CierreLaboralController;
use App\Http\Controllers\Rh\DocumentoLaboralController;
use App\Http\Controllers\Rh\EvaluacionPeriodoPruebaController;
use App\Http\Controllers\Rh\OnboardingController;
use App\Http\Controllers\Rh\PendienteController;
use App\Http\Controllers\Rh\ReingresoController;
use Illuminate\Support\Facades\Route;

/*
| Ciclo laboral (docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md): bandeja de
| pendientes, ciclo por persona, onboarding, periodo de prueba, cierre,
| reingreso y control del original físico. El workflow de candidatos vive
| en routes/rh.php. Cada controlador autoriza (Policy/permiso + alcance) y
| delega al MISMO Service que la API v1.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('can:modo-colaborador')->group(function () {
        Route::get('mi-proceso', [MiProcesoController::class, 'show'])->name('portal.mi-proceso');
        Route::post('mi-proceso/onboarding/{avance}/evaluacion', [MiProcesoController::class, 'evaluacion'])
            ->name('portal.onboarding.evaluacion')
            ->middleware('throttle:30,1');
    });

    Route::prefix('rh')->name('rh.')->group(function () {
        Route::get('pendientes', [PendienteController::class, 'index'])->name('pendientes.index');

        Route::get('colaboradores/{colaborador}/ciclo', [CicloColaboradorController::class, 'show'])->name('colaboradores.ciclo')->withTrashed();
        Route::post('colaboradores/{colaborador}/cierres', [CierreLaboralController::class, 'store'])->name('colaboradores.cierres.store');

        Route::prefix('onboarding')->name('onboarding.')->group(function () {
            Route::get('configuracion', [OnboardingController::class, 'configuracion'])->name('configuracion');
            Route::post('modulos', [OnboardingController::class, 'guardarModulo'])->name('modulos.store');
            Route::put('modulos/{modulo}', [OnboardingController::class, 'actualizarModulo'])->name('modulos.update');
            Route::post('tipos-activo', [OnboardingController::class, 'guardarTipoActivo'])->name('tiposActivo.store');
            Route::put('tipos-activo/{tipoActivo}', [OnboardingController::class, 'actualizarTipoActivo'])->name('tiposActivo.update');
            Route::post('{proceso}/activos', [OnboardingController::class, 'entregarActivo'])->name('activos');
            Route::post('{proceso}/completar', [OnboardingController::class, 'completar'])->name('completar');
            Route::post('avances/{avance}/retroalimentacion', [OnboardingController::class, 'retroalimentar'])->name('retroalimentar');
        });

        Route::prefix('evaluaciones')->name('evaluaciones.')->group(function () {
            Route::post('{evaluacion}/capturar', [EvaluacionPeriodoPruebaController::class, 'capturar'])->name('capturar');
            Route::post('{evaluacion}/autorizar', [EvaluacionPeriodoPruebaController::class, 'autorizar'])->name('autorizar');
            Route::post('{evaluacion}/devolver', [EvaluacionPeriodoPruebaController::class, 'devolver'])->name('devolver');
        });

        Route::prefix('cierres/{cierre}')->name('cierres.')->group(function () {
            Route::post('preautorizar', [CierreLaboralController::class, 'preautorizar'])->name('preautorizar');
            Route::post('autorizar', [CierreLaboralController::class, 'autorizar'])->name('autorizar');
            Route::post('rechazar', [CierreLaboralController::class, 'rechazar'])->name('rechazar');
            Route::post('devolver', [CierreLaboralController::class, 'devolver'])->name('devolver');
            Route::post('aviso', [CierreLaboralController::class, 'aviso'])->name('aviso');
            Route::post('finiquito/calcular', [CierreLaboralController::class, 'calcularFiniquito'])->name('calcularFiniquito');
            Route::post('finiquito/autorizar', [CierreLaboralController::class, 'autorizarFiniquito'])->name('autorizarFiniquito');
            Route::post('pago/programar', [CierreLaboralController::class, 'programarPago'])->name('programarPago');
            Route::post('cita', [CierreLaboralController::class, 'cita'])->name('cita');
            Route::post('finiquito/firmado', [CierreLaboralController::class, 'finiquitoFirmado'])->name('finiquitoFirmado');
            Route::post('pago/confirmar', [CierreLaboralController::class, 'confirmarPago'])->name('confirmarPago');
            Route::post('cerrar', [CierreLaboralController::class, 'cerrar'])->name('cerrar');
            Route::post('cancelar', [CierreLaboralController::class, 'cancelar'])->name('cancelar');
        });

        Route::prefix('reingresos')->name('reingresos.')->group(function () {
            Route::get('/', [ReingresoController::class, 'index'])->name('index');
            Route::post('/', [ReingresoController::class, 'store'])->name('store');
            Route::post('{reingreso}/decidir', [ReingresoController::class, 'decidir'])->name('decidir');
        });

        Route::prefix('documentos-laborales/{documento}')->name('documentos-laborales.')->group(function () {
            Route::get('descargar', [DocumentoLaboralController::class, 'descargar'])->name('descargar');
            Route::post('imprimir', [DocumentoLaboralController::class, 'imprimir'])->name('imprimir');
            Route::post('firma-fisica', [DocumentoLaboralController::class, 'firmaFisica'])->name('firmaFisica');
            Route::post('envio', [DocumentoLaboralController::class, 'envio'])->name('envio');
            Route::post('recepcion', [DocumentoLaboralController::class, 'recepcion'])->name('recepcion');
            Route::post('escaneo', [DocumentoLaboralController::class, 'escaneo'])->name('escaneo');
            Route::post('archivar', [DocumentoLaboralController::class, 'archivar'])->name('archivar');
        });
    });
});
