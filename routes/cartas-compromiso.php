<?php

use App\Http\Controllers\Rh\CartaCompromisoController;
use Illuminate\Support\Facades\Route;

/*
| Carta compromiso de documentos pendientes (CartaCompromisoService): PDF
| desde el expediente web. Acceso = ver el expediente
| (AlcanceOrganizacionalService::puedeVerExpediente).
*/

Route::middleware(['auth', 'verified'])->prefix('rh/expedientes')->name('rh.expedientes.')->group(function () {
    Route::get('{colaborador}/carta-compromiso/pdf', [CartaCompromisoController::class, 'pdf'])->name('carta-compromiso.pdf')->withTrashed();
});
