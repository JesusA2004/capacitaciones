<?php

use App\Http\Controllers\AltaPublicaController;
use Illuminate\Support\Facades\Route;

// Publica (sin sesion iniciada): liga segura de alta digital por token.
// No lleva 'auth'/'verified': el candidato no tiene cuenta de usuario.
Route::prefix('alta/{alta:token}')->name('alta-publica.')->group(function () {
    Route::get('/', [AltaPublicaController::class, 'show'])->name('show');
    Route::put('datos-personales', [AltaPublicaController::class, 'guardarDatosPersonales'])->name('datos-personales');
    Route::post('foto', [AltaPublicaController::class, 'subirFoto'])->name('foto');
    Route::post('documentos', [AltaPublicaController::class, 'subirDocumento'])->name('documentos');
    Route::put('consentimientos', [AltaPublicaController::class, 'guardarConsentimientos'])->name('consentimientos');
    Route::post('enviar', [AltaPublicaController::class, 'enviar'])->name('enviar');
});
