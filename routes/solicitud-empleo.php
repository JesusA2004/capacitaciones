<?php

use App\Http\Controllers\Rh\PedirInformacionController;
use App\Http\Controllers\Rh\SolicitudEmpleoIngresoController;
use App\Services\Incorporacion\SolicitudEmpleo\CatalogoSolicitudEmpleo;
use Illuminate\Support\Facades\Route;

/*
| Solicitud de empleo + Estudio socioeconómico (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md):
| RH ve lo que la persona capturó por sección y completa solo lo faltante
| (JSON). Vista previa y generación: «Documentos del proceso»
| (documentos-proceso/colaborador/{id}, proceso incorporacion).
*/
Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.')->group(function () {
    Route::get('colaboradores/{colaborador}/solicitud-empleo', [SolicitudEmpleoIngresoController::class, 'show'])
        ->name('solicitud-empleo.show')
        ->middleware('throttle:60,1');
    // «Pedir información al colaborador»: TODO lo que le falta, un solo aviso.
    Route::get('colaboradores/{colaborador}/pedir-informacion', [PedirInformacionController::class, 'show'])
        ->name('pedir-informacion.show')
        ->middleware('throttle:60,1');
    Route::post('colaboradores/{colaborador}/pedir-informacion', [PedirInformacionController::class, 'pedir'])
        ->name('pedir-informacion.pedir')
        ->middleware('throttle:10,1');
    Route::post('colaboradores/{colaborador}/solicitud-empleo/pedir', [SolicitudEmpleoIngresoController::class, 'pedir'])
        ->name('solicitud-empleo.pedir')
        ->middleware('throttle:10,1');
    Route::post('colaboradores/{colaborador}/solicitud-empleo/{seccion}', [SolicitudEmpleoIngresoController::class, 'guardar'])
        ->name('solicitud-empleo.guardar')
        ->whereIn('seccion', array_keys(CatalogoSolicitudEmpleo::SECCIONES))
        ->middleware('throttle:60,1');
});
