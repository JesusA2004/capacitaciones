<?php

use App\Http\Controllers\Reuniones\AsistenciaController;
use App\Http\Controllers\Reuniones\SesionEnVivoController;
use App\Http\Controllers\Reuniones\ZoomWebhookController;
use Illuminate\Support\Facades\Route;

// Llamado por Zoom directamente, sin sesión de Laravel ni token CSRF (ver
// la excepción en bootstrap/app.php). La firma HMAC (x-zm-signature) es la
// única protección real de esta ruta.
Route::post('webhooks/zoom', ZoomWebhookController::class)->name('webhooks.zoom');

Route::middleware(['auth', 'verified', 'feature:capacitacion'])->prefix('sesiones/{sesion}')->name('sesiones.')->group(function () {
    Route::put('/', [SesionEnVivoController::class, 'update'])->name('update');
    Route::delete('/', [SesionEnVivoController::class, 'destroy'])->name('destroy');

    Route::get('asistencias', [AsistenciaController::class, 'index'])->name('asistencias.index');
    Route::post('asistencias/{asistencia}', [AsistenciaController::class, 'marcar'])->name('asistencias.marcar');
});
