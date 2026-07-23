<?php

use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\CertificadoVerificacionController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Publica (sin sesion iniciada): verificacion de constancias por folio.
Route::get('constancias/verificar/{folio}', [CertificadoVerificacionController::class, 'show'])->name('constancias.verificar');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::inertia('capacitacion', 'Capacitacion/Proximamente')->name('capacitacion.proximamente');

    Route::inertia('planeacion-rh', 'PlaneacionRh/Index')
        ->middleware('role:super_admin')
        ->name('planeacion-rh');

    Route::middleware('feature:capacitacion')->group(function () {
        Route::get('calendario', [CalendarioController::class, 'index'])->name('calendario');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/administracion.php';
require __DIR__.'/rh.php';
require __DIR__.'/cursos.php';
require __DIR__.'/asignaciones.php';
require __DIR__.'/mi-capacitacion.php';
require __DIR__.'/multimedia.php';
require __DIR__.'/cuestionarios.php';
require __DIR__.'/actividades.php';
require __DIR__.'/reuniones.php';
require __DIR__.'/reportes.php';
require __DIR__.'/notificaciones.php';
