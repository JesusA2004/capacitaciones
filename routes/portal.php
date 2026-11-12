<?php

use App\Http\Controllers\Colaborador\MiSolicitudEmpleoController;
use App\Http\Controllers\Colaborador\PortalController;
use App\Http\Controllers\Portal\MisDocumentosController;
use App\Http\Controllers\Portal\MisDocumentosLaboralesController;
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

        // Documentos del propio expediente: mismas reglas que la app
        // (IncorporacionService). `portal.documentos.subir` también lo usa
        // el asistente de registro web.
        Route::post('mi-expediente/documentos/{documentType}/subir', [MisDocumentosController::class, 'subir'])
            ->name('portal.documentos.subir')
            ->whereNumber('documentType')
            ->middleware('throttle:api-cargas');
        Route::post('mi-expediente/documentos/{documentType}/solicitar-cambio', [MisDocumentosController::class, 'solicitarCambio'])
            ->name('portal.documentos.solicitar-cambio')
            ->whereNumber('documentType')
            ->middleware('throttle:20,1');

        // Mis documentos laborales (consulta, descarga y firma digital) y
        // Mis contratos: lo mismo que la app (CicloLaboralColaboradorController).
        Route::prefix('mis-documentos-laborales')->name('portal.documentos-laborales.')->group(function () {
            Route::get('/', [MisDocumentosLaboralesController::class, 'index'])->name('index');
            Route::get('{documento}', [MisDocumentosLaboralesController::class, 'show'])->name('show')->whereNumber('documento');
            Route::get('{documento}/ver', [MisDocumentosLaboralesController::class, 'ver'])->name('ver')->whereNumber('documento');
            Route::get('{documento}/descargar', [MisDocumentosLaboralesController::class, 'descargar'])->name('descargar')->whereNumber('documento');
            Route::post('{documento}/apertura', [MisDocumentosLaboralesController::class, 'apertura'])->name('apertura')->whereNumber('documento');
            Route::post('{documento}/firmar', [MisDocumentosLaboralesController::class, 'firmar'])->name('firmar')->whereNumber('documento')->middleware('throttle:10,1');
        });
        Route::get('mis-contratos', [MisDocumentosLaboralesController::class, 'contratos'])->name('portal.contratos');
    });
});
