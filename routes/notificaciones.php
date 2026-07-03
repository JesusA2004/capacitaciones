<?php

use App\Http\Controllers\NotificacionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('notificaciones')->name('notificaciones.')->group(function () {
    Route::get('/', [NotificacionController::class, 'index'])->name('index');
    Route::post('{notificacion}/leida', [NotificacionController::class, 'marcarLeida'])->name('marcar-leida');
    Route::post('leer-todas', [NotificacionController::class, 'marcarTodasLeidas'])->name('marcar-todas-leidas');
});
