<?php

use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\WebPushController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('notificaciones')->name('notificaciones.')->group(function () {
    Route::get('/', [NotificacionController::class, 'index'])->name('index');
    Route::get('centro', [NotificacionController::class, 'centro'])->name('centro');

    // Notificaciones de escritorio (Web Push) del navegador actual.
    Route::post('web-push', [WebPushController::class, 'suscribir'])->middleware('throttle:20,1')->name('web-push.suscribir');
    Route::delete('web-push', [WebPushController::class, 'desuscribir'])->name('web-push.desuscribir');
    Route::post('web-push/prueba', [WebPushController::class, 'prueba'])->middleware('throttle:5,1')->name('web-push.prueba');

    Route::post('{notificacion}/leida', [NotificacionController::class, 'marcarLeida'])->name('marcar-leida');
    Route::post('{notificacion}/abrir', [NotificacionController::class, 'abrir'])->name('abrir');
    Route::get('{notificacion}/ir', [NotificacionController::class, 'ir'])->name('ir');
    Route::post('leer-todas', [NotificacionController::class, 'marcarTodasLeidas'])->name('marcar-todas-leidas');
});
