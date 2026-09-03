<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ColaboradorController;
use App\Http\Controllers\Api\V1\NotificacionController;
use App\Http\Controllers\Api\V1\SolicitudController;
use App\Http\Controllers\Api\V1\VacacionesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — app móvil de colaboradores
|--------------------------------------------------------------------------
|
| Autenticación por token personal (Laravel Sanctum), sin cookies ni CSRF:
| cada dispositivo obtiene su propio token en /api/v1/login y lo manda como
| "Authorization: Bearer <token>" en cada request subsecuente. Ver
| docs/API_MOVIL.md.
|
| Fase 1: solo colaborador propio (perfil, vacaciones, solicitudes,
| notificaciones). RH/reclutamiento/reportes quedan solo en la web por
| ahora (ver docs/API_MOVIL.md, "Fuera de alcance de Fase 1").
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::prefix('colaborador')->name('colaborador.')->group(function () {
            Route::get('perfil', [ColaboradorController::class, 'perfil'])->name('perfil');
            Route::get('dashboard', [ColaboradorController::class, 'dashboard'])->name('dashboard');
            Route::get('vacaciones', [ColaboradorController::class, 'vacaciones'])->name('vacaciones');
            Route::get('solicitudes', [ColaboradorController::class, 'solicitudes'])->name('solicitudes.index');
            Route::post('solicitudes', [ColaboradorController::class, 'storeSolicitud'])->name('solicitudes.store');
            Route::get('notificaciones', [ColaboradorController::class, 'notificaciones'])->name('notificaciones');
        });

        Route::prefix('vacaciones')->name('vacaciones.')->group(function () {
            Route::get('saldo', [VacacionesController::class, 'saldo'])->name('saldo');
            Route::get('solicitudes', [VacacionesController::class, 'solicitudes'])->name('solicitudes.index');
            Route::post('solicitudes', [VacacionesController::class, 'storeSolicitud'])->name('solicitudes.store');
        });

        Route::prefix('solicitudes')->name('solicitudes.')->group(function () {
            Route::get('/', [SolicitudController::class, 'index'])->name('index');
            Route::post('/', [SolicitudController::class, 'store'])->name('store');
            Route::get('{solicitud}', [SolicitudController::class, 'show'])->name('show');
        });

        Route::prefix('notificaciones')->name('notificaciones.')->group(function () {
            Route::get('/', [NotificacionController::class, 'index'])->name('index');
            Route::post('{notificacion}/leer', [NotificacionController::class, 'marcarLeida'])->name('leer');
        });
    });
});
