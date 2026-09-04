<?php

use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\CertificadoVerificacionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncorporacionQrController;
use App\Http\Controllers\VacacionesController;
use Illuminate\Support\Facades\Route;

// El index del sistema es el login: sin sesion se muestra el login, con
// sesion se entra directo al dashboard/inicio. No hay landing intermedia.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

// Publica (sin sesion iniciada): verificacion de constancias por folio.
Route::get('constancias/verificar/{folio}', [CertificadoVerificacionController::class, 'show'])->name('constancias.verificar');

// Publica (sin sesion iniciada): pantalla del QR de incorporacion que RH
// genera/imprime (ver config('incorporacion.qr_url_base')). Nunca marca la
// invitacion como usada ni responde 404/500 con un token invalido — ver
// App\Http\Controllers\IncorporacionQrController.
Route::get('incorporacion/qr/{token}', [IncorporacionQrController::class, 'show'])->name('incorporacion.qr');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::inertia('capacitacion', 'Capacitacion/Proximamente')->name('capacitacion.proximamente');

    Route::prefix('vacaciones')->name('vacaciones.')->group(function () {
        Route::get('/', [VacacionesController::class, 'index'])->name('index');
        Route::post('/', [VacacionesController::class, 'store'])->name('store');
        Route::post('{solicitud}/cancelar', [VacacionesController::class, 'cancelar'])->name('cancelar');
    });

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
require __DIR__.'/solicitudes.php';
require __DIR__.'/portal.php';
require __DIR__.'/alta-publica.php';
require __DIR__.'/cursos.php';
require __DIR__.'/asignaciones.php';
require __DIR__.'/mi-capacitacion.php';
require __DIR__.'/multimedia.php';
require __DIR__.'/cuestionarios.php';
require __DIR__.'/actividades.php';
require __DIR__.'/reuniones.php';
require __DIR__.'/reportes.php';
require __DIR__.'/notificaciones.php';
