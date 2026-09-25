<?php

use App\Http\Controllers\AppDownloadController;
use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\CelebracionController;
use App\Http\Controllers\CertificadoVerificacionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncorporacionQrController;
use App\Http\Controllers\NavigationModeController;
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

// Publica (sin sesion iniciada): descarga directa de la app movil mientras
// no este en Play Store (ver config('mobile_releases') y docs/APP_RELEASES.md).
Route::prefix('app')->name('app.')->group(function () {
    Route::get('/', [AppDownloadController::class, 'index'])->name('index');
    Route::get('versiones', [AppDownloadController::class, 'versiones'])->name('versiones');
    Route::get('descargar', [AppDownloadController::class, 'descargar'])->name('descargar');
    Route::get('descargar/{platform}', [AppDownloadController::class, 'descargarPlataforma'])->name('descargar.plataforma');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/rotacion', [DashboardController::class, 'rotacion'])->name('dashboard.rotacion');
    Route::get('dashboard/rotacion/excel', [DashboardController::class, 'rotacionExcel'])->name('dashboard.rotacion.excel');
    Route::get('dashboard/rotacion/pdf', [DashboardController::class, 'rotacionPdf'])->name('dashboard.rotacion.pdf');

    Route::post('modo-navegacion', [NavigationModeController::class, 'update'])->name('modo-navegacion.update');

    Route::inertia('capacitacion', 'Capacitacion/Proximamente')->name('capacitacion.proximamente');

    // Guía ilustrada del sistema: recorridos guiados por módulo + botón de
    // ayuda flotante (ver resources/js/components/sistema/*, resources/js/lib/tours/*).
    Route::inertia('ayuda', 'Ayuda/Index')->name('ayuda');

    // Celebraciones (cumpleaños y aniversarios): pantalla del evento, destino
    // de sus notificaciones; felicitaciones privadas (docs/CELEBRACIONES.md).
    Route::prefix('celebraciones')->name('celebraciones.')->group(function () {
        Route::get('hoy', [CelebracionController::class, 'hoy'])->name('hoy');
        Route::get('{celebracion}', [CelebracionController::class, 'show'])->name('show');
        Route::get('{celebracion}/tarjeta', [CelebracionController::class, 'tarjeta'])->name('tarjeta');
        Route::get('{celebracion}/foto', [CelebracionController::class, 'foto'])->name('foto');
        Route::post('{celebracion}/mensajes', [CelebracionController::class, 'storeMensaje'])->name('mensajes.store')->middleware('throttle:20,1');
        Route::put('{celebracion}/mensajes/{mensaje}', [CelebracionController::class, 'updateMensaje'])->name('mensajes.update');
        Route::delete('{celebracion}/mensajes/{mensaje}', [CelebracionController::class, 'destroyMensaje'])->name('mensajes.destroy');
        Route::get('{celebracion}/mensajes/{mensaje}/foto', [CelebracionController::class, 'mensajeFoto'])->name('mensajes.foto');
        Route::get('{celebracion}/mensajes/{mensaje}/autor-foto', [CelebracionController::class, 'autorFoto'])->name('mensajes.autor-foto');
    });

    // Las vacaciones se solicitan y cancelan desde el módulo unificado de
    // Solicitudes (tipo `vacaciones`, ver docs/SOLICITUDES_UNIFICADAS.md).
    // El módulo web standalone `/vacaciones` (App\Http\Controllers\
    // VacacionesController, tabla legacy solicitudes_vacaciones) se retiró:
    // seguía activo en "Mi portal" pero enviaba las solicitudes a una tabla
    // que la bandeja de RH (rh.solicitudes.*) nunca revisaba. La tabla y el
    // servicio (VacacionesService, saldo()) se conservan para historial,
    // reportes y compatibilidad con la API móvil (api/v1/vacaciones/*).
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
