<?php

use App\Http\Controllers\Registro\AsistenteRegistroController;
use App\Http\Controllers\Registro\RegistroQrWebController;
use Illuminate\Support\Facades\Route;

/*
| Registro por QR desde la web (iPhone/Mac, sin la APK): la persona crea su
| cuenta en la pantalla del QR y termina el MISMO registro que pide la app
| (RegistroAppService). Mientras su registro esté abierto, el resto de la web
| la regresa aquí (App\Http\Middleware\ExigirRegistroWeb).
*/

// Pública: el token del QR es la única puerta (IncorporacionInvitacionService).
Route::post('incorporacion/qr/{token}/registrar', [RegistroQrWebController::class, 'registrar'])
    ->name('incorporacion.qr.registrar')
    ->middleware('throttle:10,1');

Route::middleware(['auth', 'verified'])->prefix('mi-registro')->name('mi-registro.')->group(function () {
    Route::get('/', [AsistenteRegistroController::class, 'show'])->name('show');
    Route::post('datos', [AsistenteRegistroController::class, 'datos'])->name('datos')->middleware('throttle:60,1');
    Route::post('documentos/{documentoRequerido}', [AsistenteRegistroController::class, 'subirDocumento'])
        ->name('documentos.subir')
        ->whereNumber('documentoRequerido')
        ->middleware('throttle:30,1');
    Route::post('carta-compromiso/vista-previa', [AsistenteRegistroController::class, 'vistaPreviaCarta'])->name('carta-compromiso.vista-previa')->middleware('throttle:20,1');
    Route::post('finalizar', [AsistenteRegistroController::class, 'finalizar'])->name('finalizar')->middleware('throttle:20,1');
});
