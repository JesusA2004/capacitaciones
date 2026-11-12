<?php

use App\Http\Controllers\Rh\CartaCompromisoController;
use App\Http\Controllers\Rh\GestionCartasCompromisoController;
use App\Http\Controllers\Rh\MiCartaCompromisoController;
use Illuminate\Support\Facades\Route;

/*
| Carta compromiso de documentos pendientes (CartaCompromisoService): PDF
| desde el expediente web. Acceso = ver el expediente
| (AlcanceOrganizacionalService::puedeVerExpediente).
*/

Route::middleware(['auth', 'verified'])->prefix('rh/expedientes')->name('rh.expedientes.')->group(function () {
    Route::get('{colaborador}/carta-compromiso/pdf', [CartaCompromisoController::class, 'pdf'])->name('carta-compromiso.pdf')->withTrashed();
});

/*
| Módulo «Cartas compromiso» (grupo 2 · Contratación y expediente): lista,
| generación manual con vista previa (RH, gerente de sucursal, gerente
| regional) y carga del escaneo firmado. Autorización en
| App\Policies\CartaCompromisoPolicy; reglas en
| GestionCartasCompromisoService.
*/
Route::middleware(['auth', 'verified'])->prefix('rh/cartas-compromiso')->name('rh.cartas-compromiso.')->group(function () {
    Route::get('/', [GestionCartasCompromisoController::class, 'index'])->name('index');
    Route::get('nueva', [GestionCartasCompromisoController::class, 'create'])->name('create');
    Route::get('colaboradores', [GestionCartasCompromisoController::class, 'colaboradores'])->name('colaboradores');
    Route::get('colaboradores/{colaborador}', [GestionCartasCompromisoController::class, 'preparar'])->name('preparar')->whereNumber('colaborador');
    Route::post('colaboradores/{colaborador}/vista-previa', [GestionCartasCompromisoController::class, 'vistaPrevia'])->name('vista-previa')->whereNumber('colaborador')->middleware('throttle:20,1');
    Route::post('colaboradores/{colaborador}', [GestionCartasCompromisoController::class, 'store'])->name('store')->whereNumber('colaborador')->middleware('throttle:20,1');
    Route::get('{carta}', [GestionCartasCompromisoController::class, 'show'])->name('show')->whereNumber('carta');
    Route::get('{carta}/pdf', [GestionCartasCompromisoController::class, 'pdf'])->name('pdf')->whereNumber('carta');
    Route::post('{carta}/firmada', [GestionCartasCompromisoController::class, 'subirFirmada'])->name('subir-firmada')->whereNumber('carta')->middleware('throttle:20,1');
    Route::get('{carta}/firmada', [GestionCartasCompromisoController::class, 'verFirmada'])->name('firmada')->whereNumber('carta');
    // Aprobación final de RH de la carta que pidió la propia persona
    // (CartaCompromisoPolicy::aprobar, permiso cartas_compromiso.aprobar).
    Route::post('{carta}/aprobar', [GestionCartasCompromisoController::class, 'aprobar'])->name('aprobar')->whereNumber('carta')->middleware('throttle:20,1');
    Route::post('{carta}/rechazar', [GestionCartasCompromisoController::class, 'rechazar'])->name('rechazar')->whereNumber('carta')->middleware('throttle:20,1');
});

/*
| «Mi expediente» → «Pedir carta compromiso» (modo colaborador): la propia
| persona elige sus documentos pendientes, ve la vista previa y la envía;
| queda pendiente de aprobación de RH. Reglas en
| SolicitudCartaCompromisoService (mismo que la app).
*/
Route::middleware(['auth', 'verified', 'can:modo-colaborador'])->prefix('mi-expediente/carta-compromiso')->name('portal.carta-compromiso.')->group(function () {
    Route::post('vista-previa', [MiCartaCompromisoController::class, 'vistaPrevia'])->name('vista-previa')->middleware('throttle:20,1');
    Route::post('/', [MiCartaCompromisoController::class, 'store'])->name('store')->middleware('throttle:10,1');
});
