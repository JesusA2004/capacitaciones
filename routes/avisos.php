<?php

use App\Http\Controllers\Colaborador\AvisoController as AvisoColaboradorController;
use App\Http\Controllers\Rh\AvisoController as AvisoRhController;
use Illuminate\Support\Facades\Route;

/*
| Avisos de RH (mensaje + imagen, a toda la empresa o a un colaborador):
| panel de envío (RH, permiso avisos.enviar) y bandeja propia del
| colaborador (ver docs/API_MOVIL.md para el equivalente móvil).
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('rh/avisos')->name('rh.avisos.')->group(function () {
        Route::get('/', [AvisoRhController::class, 'index'])->name('index');
        Route::post('/', [AvisoRhController::class, 'store'])->name('store')->middleware('throttle:20,1');
        Route::get('colaboradores', [AvisoRhController::class, 'buscarColaboradores'])->name('colaboradores')->middleware('throttle:60,1');
        Route::get('{aviso}/imagen', [AvisoRhController::class, 'imagen'])->name('imagen');
    });

    Route::prefix('avisos')->name('avisos.')->group(function () {
        Route::get('/', [AvisoColaboradorController::class, 'index'])->name('index');
        Route::post('{aviso}/leido', [AvisoColaboradorController::class, 'marcarLeido'])->name('leido');
        Route::get('{aviso}/imagen', [AvisoColaboradorController::class, 'imagen'])->name('imagen');
    });
});
