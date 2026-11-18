<?php

use App\Http\Controllers\Rh\CambioSucursalController;
use Illuminate\Support\Facades\Route;

/*
| «Cambio de sucursal» (mismo puesto, o puesto y sucursal): Puestos y
| salarios → Trasladar personas y expediente → Datos laborales. Permiso
| expedientes.cambiar_sucursal (CambioSucursalRequest); la regla vive en
| CambioSucursalService.
*/
Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.')->group(function () {
    Route::post('cambio-sucursal/vista-previa', [CambioSucursalController::class, 'vistaPrevia'])
        ->name('cambio-sucursal.vista-previa')->middleware('throttle:120,1');
    Route::post('cambio-sucursal', [CambioSucursalController::class, 'store'])
        ->name('cambio-sucursal.store')->middleware('throttle:20,1');
});
