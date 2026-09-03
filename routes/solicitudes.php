<?php

use App\Http\Controllers\Solicitudes\SolicitudInternaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('solicitudes')->name('solicitudes.')->group(function () {
        Route::get('/', [SolicitudInternaController::class, 'index'])->name('index');
        Route::post('/', [SolicitudInternaController::class, 'store'])->name('store');
        Route::get('{solicitud}', [SolicitudInternaController::class, 'show'])->name('show');
        Route::post('{solicitud}/cancelar', [SolicitudInternaController::class, 'cancelar'])->name('cancelar');
        Route::post('{solicitud}/documentos', [SolicitudInternaController::class, 'subirDocumento'])->name('documentos.store');
    });
});
