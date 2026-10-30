<?php

use App\Http\Controllers\Reportes\TableroRotacionController;
use App\Http\Controllers\Rh\BajaController;
use App\Http\Controllers\Rh\BajaDocumentoSalidaController;
use App\Http\Controllers\Rh\FiniquitoBajasController;
use Illuminate\Support\Facades\Route;

/*
| Módulo de Bajas y Causales (docs/BAJAS_Y_CAUSALES.md). La baja es
| DIRECTA: «store» la registra y la aplica de inmediato (sin borrador, envío
| ni aprobación). Cada acción autoriza con BajaPolicy (permisos bajas.*) +
| alcance y delega a BajaService, el mismo de la API v1. El catálogo de
| causales se administra en Bajas y finiquitos → «Causales».
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('rh/bajas')->name('rh.bajas.')->group(function () {
        Route::get('/', [BajaController::class, 'index'])->name('index');
        Route::get('nueva', [BajaController::class, 'create'])->name('create');
        // Pestañas de Cierres laborales → Bajas (PDF Etapa 1, Pantalla 4).
        Route::get('rotacion', [TableroRotacionController::class, 'index'])->name('rotacion');
        Route::post('rotacion/metas', [TableroRotacionController::class, 'guardarMeta'])->name('rotacion-metas');
        Route::get('causales', [BajaController::class, 'causales'])->name('causales');
        Route::get('causales/excel', [BajaController::class, 'causalesExcel'])->name('causales-excel')->middleware('throttle:20,1');
        Route::prefix('finiquitos')->name('finiquitos.')->group(function () {
            Route::get('/', [FiniquitoBajasController::class, 'index'])->name('index');
            Route::post('{finiquito}/recalcular', [FiniquitoBajasController::class, 'recalcular'])->name('recalcular');
            Route::post('{finiquito}/revisar', [FiniquitoBajasController::class, 'revisar'])->name('revisar');
            Route::post('{finiquito}/ajustar', [FiniquitoBajasController::class, 'ajustar'])->name('ajustar');
            Route::post('{finiquito}/tipo', [FiniquitoBajasController::class, 'cambiarTipo'])->name('tipo');
            Route::get('{finiquito}/recibo', [FiniquitoBajasController::class, 'recibo'])->name('recibo')->middleware('throttle:30,1');
            Route::post('{finiquito}/firmado', [FiniquitoBajasController::class, 'subirFirmado'])->name('firmado')->middleware('throttle:30,1');
            Route::post('{finiquito}/pago', [FiniquitoBajasController::class, 'registrarPago'])->name('pago');
        });
        Route::post('/', [BajaController::class, 'store'])->name('store');
        Route::get('colaboradores', [BajaController::class, 'colaboradores'])->name('colaboradores')->middleware('throttle:60,1');
        // Vista previa del finiquito propuesto ANTES de registrar (PDF, no guarda nada).
        Route::get('previa/finiquito', [BajaController::class, 'previaFiniquito'])->name('previa.finiquito')->middleware('throttle:30,1');
        Route::get('{baja}', [BajaController::class, 'show'])->name('show');
        Route::post('{baja}/enviar-juridico', [BajaController::class, 'enviarJuridico'])->name('enviar-juridico');
        Route::post('{baja}/aprobar-juridico', [BajaController::class, 'aprobarJuridico'])->name('aprobar-juridico');
        Route::post('{baja}/devolver-juridico', [BajaController::class, 'devolverJuridico'])->name('devolver-juridico');
        Route::post('{baja}/cambiar-causal', [BajaController::class, 'cambiarCausal'])->name('cambiar-causal');
        Route::patch('{baja}/datos', [BajaController::class, 'actualizar'])->name('actualizar');
        Route::post('{baja}/cerrar', [BajaController::class, 'cerrar'])->name('cerrar');
        Route::post('{baja}/reabrir', [BajaController::class, 'reabrir'])->name('reabrir');
        Route::post('{baja}/cancelar', [BajaController::class, 'cancelar'])->name('cancelar');
        Route::post('{baja}/documentos', [BajaController::class, 'adjuntar'])->name('documentos.store')->middleware('throttle:30,1');
        Route::get('{baja}/documentos/{documento}', [BajaController::class, 'verDocumento'])->name('documentos.ver');
        // Generar documento de salida (RV carta · NR convenio · RE aviso · OT constancia).
        Route::prefix('{baja}/salida')->name('salida.')->group(function () {
            Route::put('aviso', [BajaDocumentoSalidaController::class, 'guardarAviso'])->name('aviso');
            Route::post('plazo', [BajaDocumentoSalidaController::class, 'validarPlazo'])->name('plazo');
            Route::get('vista-previa', [BajaDocumentoSalidaController::class, 'vistaPrevia'])->name('vista-previa')->middleware('throttle:30,1');
            Route::post('generar', [BajaDocumentoSalidaController::class, 'generar'])->name('generar')->middleware('throttle:30,1');
            Route::get('descargar', [BajaDocumentoSalidaController::class, 'descargar'])->name('descargar');
            Route::post('firmado', [BajaDocumentoSalidaController::class, 'subirFirmado'])->name('firmado')->middleware('throttle:30,1');
            Route::post('evidencias', [BajaDocumentoSalidaController::class, 'subirEvidencia'])->name('evidencias')->middleware('throttle:30,1');
        });
        Route::delete('{baja}/documentos/{documento}', [BajaController::class, 'quitarDocumento'])->name('documentos.destroy');
    });

    // Tablero de rotación (Reportes → Rotación de personal).
    Route::prefix('reportes/rotacion/tablero')->name('reportes.rotacion.tablero.')->group(function () {
        Route::get('/', [TableroRotacionController::class, 'index'])->name('index');
        Route::get('excel', [TableroRotacionController::class, 'excel'])->name('excel')->middleware('throttle:20,1');
        Route::get('pdf', [TableroRotacionController::class, 'pdf'])->name('pdf')->middleware('throttle:20,1');
    });
});
