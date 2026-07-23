<?php

use App\Http\Controllers\Rh\EmployeeDocumentController;
use App\Http\Controllers\Rh\ExpedienteController;
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
    });
});
