<?php

use App\Http\Controllers\Colaborador\MiSolicitudEmpleoController;
use App\Http\Controllers\Colaborador\PortalController;
use App\Services\Incorporacion\SolicitudEmpleo\CatalogoSolicitudEmpleo;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // La autorización real vive aquí: Gate `modo-colaborador`
    // (App\Services\Navigation\NavigationService::puedeUsarModoColaborador()).
    // Una cuenta operativa SIN colaborador enlazado recibe 403 por URL
    // directa; una operativa CON colaborador activo tiene Mi espacio. Estas
    // pantallas siempre leen $request->user() — nunca los datos de otra persona.
    Route::middleware('can:modo-colaborador')->group(function () {
        Route::get('mi-portal', [PortalController::class, 'index'])->name('portal.index');
        Route::get('mi-perfil', [PortalController::class, 'perfil'])->name('portal.perfil');
        Route::get('mis-notificaciones', [PortalController::class, 'notificaciones'])->name('portal.notificaciones');
        // Solicitud de empleo + estudio socioeconómico: lo mismo que pide la app.
        Route::get('mi-solicitud-empleo', [MiSolicitudEmpleoController::class, 'show'])->name('portal.solicitud-empleo');
        Route::post('mi-solicitud-empleo/{seccion}', [MiSolicitudEmpleoController::class, 'guardar'])->name('portal.solicitud-empleo.guardar')
            ->whereIn('seccion', array_keys(CatalogoSolicitudEmpleo::SECCIONES))
            ->middleware('throttle:60,1');
    });
});
