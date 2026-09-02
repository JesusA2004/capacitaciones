<?php

use App\Http\Controllers\Rh\AltaDigitalController;
use App\Http\Controllers\Rh\CandidatoController;
use App\Http\Controllers\Rh\EmployeeDocumentController;
use App\Http\Controllers\Rh\ExpedienteController;
use App\Http\Controllers\Rh\FormatoController;
use App\Http\Controllers\Rh\PlantillaController;
use App\Http\Controllers\Rh\ReclutamientoController;
use App\Http\Controllers\Rh\VacacionesController;
use App\Http\Controllers\Rh\VacanteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('mi-expediente', [ExpedienteController::class, 'miExpediente'])->name('mi-expediente');

    Route::prefix('rh')->name('rh.')->group(function () {
        Route::prefix('expedientes')->name('expedientes.')->group(function () {
            Route::get('/', [ExpedienteController::class, 'index'])->name('index');
            Route::get('{colaborador}', [ExpedienteController::class, 'show'])->name('show');
            Route::put('{colaborador}/datos-personales', [ExpedienteController::class, 'actualizarDatosPersonales'])->name('datos-personales.update');
            Route::post('{colaborador}/documentos', [EmployeeDocumentController::class, 'store'])->name('documentos.store');
        });

        Route::prefix('documentos/{documento}')->name('documentos.')->group(function () {
            Route::get('descargar', [EmployeeDocumentController::class, 'descargar'])->name('descargar');
            Route::post('aprobar', [EmployeeDocumentController::class, 'aprobar'])->name('aprobar');
            Route::post('rechazar', [EmployeeDocumentController::class, 'rechazar'])->name('rechazar');
            Route::post('solicitar-correccion', [EmployeeDocumentController::class, 'solicitarCorreccion'])->name('solicitar-correccion');
        });

        Route::get('reclutamiento', [ReclutamientoController::class, 'index'])->name('reclutamiento');

        Route::prefix('vacantes')->name('vacantes.')->group(function () {
            Route::get('/', [VacanteController::class, 'index'])->name('index');
            Route::post('/', [VacanteController::class, 'store'])->name('store');
            Route::put('{vacante}', [VacanteController::class, 'update'])->name('update');
            Route::put('{vacante}/estado', [VacanteController::class, 'actualizarEstado'])->name('estado');
            Route::delete('{vacante}', [VacanteController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('candidatos')->name('candidatos.')->group(function () {
            Route::get('/', [CandidatoController::class, 'index'])->name('index');
            Route::post('/', [CandidatoController::class, 'store'])->name('store');
            Route::get('{candidato}', [CandidatoController::class, 'show'])->name('show');
            Route::put('{candidato}', [CandidatoController::class, 'update'])->name('update');
            Route::post('{candidato}/cv', [CandidatoController::class, 'subirCv'])->name('cv');
            Route::get('{candidato}/cv/descargar', [CandidatoController::class, 'descargarCv'])->name('cv.descargar');
            Route::put('{candidato}/estado', [CandidatoController::class, 'actualizarEstado'])->name('estado');
            Route::post('{candidato}/seguimientos', [CandidatoController::class, 'agregarSeguimiento'])->name('seguimientos.store');
            Route::delete('{candidato}', [CandidatoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('altas')->name('altas.')->group(function () {
            Route::get('/', [AltaDigitalController::class, 'index'])->name('index');
            Route::post('/', [AltaDigitalController::class, 'store'])->name('store');
            Route::get('{alta}', [AltaDigitalController::class, 'show'])->name('show');
            Route::get('{alta}/foto', [AltaDigitalController::class, 'descargarFoto'])->name('foto');
            Route::get('{alta}/firma', [AltaDigitalController::class, 'descargarFirma'])->name('firma');
            Route::get('{alta}/documentos/{documento}', [AltaDigitalController::class, 'descargarDocumento'])->name('documentos.descargar');
            Route::post('{alta}/enviar', [AltaDigitalController::class, 'enviar'])->name('enviar');
            Route::put('{alta}/revisar', [AltaDigitalController::class, 'revisar'])->name('revisar');
            Route::post('{alta}/aprobar', [AltaDigitalController::class, 'aprobar'])->name('aprobar');
            Route::post('{alta}/rechazar', [AltaDigitalController::class, 'rechazar'])->name('rechazar');
            Route::post('{alta}/cancelar', [AltaDigitalController::class, 'cancelar'])->name('cancelar');
        });

        Route::prefix('plantillas')->name('plantillas.')->group(function () {
            Route::get('/', [PlantillaController::class, 'index'])->name('index');
            Route::post('/', [PlantillaController::class, 'store'])->name('store');
            Route::post('{plantilla}', [PlantillaController::class, 'update'])->name('update');
            Route::delete('{plantilla}', [PlantillaController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('formatos')->name('formatos.')->group(function () {
            Route::get('/', [FormatoController::class, 'index'])->name('index');
            Route::post('/', [FormatoController::class, 'store'])->name('store');
            Route::get('{documento}/descargar', [FormatoController::class, 'descargar'])->name('descargar');
            Route::delete('{documento}', [FormatoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('vacaciones')->name('vacaciones.')->group(function () {
            Route::get('/', [VacacionesController::class, 'index'])->name('index');
            Route::post('{solicitud}/aprobar', [VacacionesController::class, 'aprobar'])->name('aprobar');
            Route::post('{solicitud}/rechazar', [VacacionesController::class, 'rechazar'])->name('rechazar');
        });
    });
});
