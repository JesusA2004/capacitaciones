<?php

use App\Http\Controllers\Rh\AltaDigitalController;
use App\Http\Controllers\Rh\CandidatoController;
use App\Http\Controllers\Rh\CumpleanosConfiguracionController;
use App\Http\Controllers\Rh\CumpleanosController;
use App\Http\Controllers\Rh\DocumentExtraccionController;
use App\Http\Controllers\Rh\EmployeeDocumentController;
use App\Http\Controllers\Rh\ExpedienteController;
use App\Http\Controllers\Rh\FiniquitoController;
use App\Http\Controllers\Rh\FormatoController;
use App\Http\Controllers\Rh\FormatoOficialController;
use App\Http\Controllers\Rh\IncorporacionInvitacionController;
use App\Http\Controllers\Rh\PlantillaController;
use App\Http\Controllers\Rh\ReporteRhController;
use App\Http\Controllers\Rh\SolicitudController;
use App\Http\Controllers\Rh\VacanteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Solo la experiencia de "modo colaborador" ve su propio expediente por
    // esta ruta — un operativo puro sin `portal.ver` recibe 403 (sección 25
    // del cierre: separación real colaborador/operativo, no solo de sidebar).
    Route::get('mi-expediente', [ExpedienteController::class, 'miExpediente'])->name('mi-expediente')->middleware('can:portal.ver');

    Route::prefix('rh')->name('rh.')->group(function () {
        Route::prefix('expedientes')->name('expedientes.')->group(function () {
            Route::get('/', [ExpedienteController::class, 'index'])->name('index');
            Route::get('exportar-excel', [ExpedienteController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [ExpedienteController::class, 'exportarPdf'])->name('exportarPdf');
            // withTrashed(): un colaborador dado de baja (soft-deleted, ver
            // Administracion\UsuarioController::destroy()) debe poder seguir
            // abriéndose desde Expedientes -- es donde ahora vive la acción
            // "Reactivar colaborador", no solo en Usuarios.
            Route::get('{colaborador}', [ExpedienteController::class, 'show'])->name('show')->withTrashed();
            Route::get('{colaborador}/foto', [ExpedienteController::class, 'descargarFoto'])->name('foto')->withTrashed();
            Route::put('{colaborador}/datos-personales', [ExpedienteController::class, 'actualizarDatosPersonales'])->name('datos-personales.update');
            Route::put('{colaborador}/avisos', [ExpedienteController::class, 'registrarAvisos'])->name('avisos.update');
            Route::post('{colaborador}/documentos', [EmployeeDocumentController::class, 'store'])->name('documentos.store');
        });

        Route::prefix('documentos/{documento}')->name('documentos.')->group(function () {
            Route::get('descargar', [EmployeeDocumentController::class, 'descargar'])->name('descargar');
            Route::post('aprobar', [EmployeeDocumentController::class, 'aprobar'])->name('aprobar');
            Route::post('rechazar', [EmployeeDocumentController::class, 'rechazar'])->name('rechazar');
            Route::post('solicitar-correccion', [EmployeeDocumentController::class, 'solicitarCorreccion'])->name('solicitar-correccion');

            // Extraccion automatica de datos personales (docs/DOCUMENT_EXTRACTION.md).
            Route::prefix('extraccion')->name('extraccion.')->group(function () {
                Route::get('/', [DocumentExtraccionController::class, 'show'])->name('show');
                Route::post('aplicar', [DocumentExtraccionController::class, 'aplicar'])->name('aplicar');
                Route::post('ignorar', [DocumentExtraccionController::class, 'ignorar'])->name('ignorar');
                Route::post('reprocesar', [DocumentExtraccionController::class, 'reprocesar'])->name('reprocesar');
            });
        });

        // Invitaciones de incorporacion por QR temporal: unica puerta de
        // entrada para que un colaborador pueda registrarse en la app (ver
        // App\Services\Incorporacion\IncorporacionInvitacionService).
        Route::prefix('incorporacion/invitaciones')->name('incorporacion.invitaciones.')->group(function () {
            Route::get('/', [IncorporacionInvitacionController::class, 'index'])->name('index');
            Route::post('/', [IncorporacionInvitacionController::class, 'store'])->name('store');
            Route::get('{invitacion}', [IncorporacionInvitacionController::class, 'show'])->name('show');
            Route::get('{invitacion}/qr', [IncorporacionInvitacionController::class, 'qr'])->name('qr');
            Route::post('{invitacion}/regenerar', [IncorporacionInvitacionController::class, 'regenerar'])->name('regenerar');
            Route::post('{invitacion}/revocar', [IncorporacionInvitacionController::class, 'revocar'])->name('revocar');
        });

        // El resumen de reclutamiento (vacantes + candidatos) vive dentro de
        // Vacantes; el módulo suelto `rh/reclutamiento` (Rh\ReclutamientoController)
        // no estaba enlazado en ningún menú y se retiró.
        Route::prefix('vacantes')->name('vacantes.')->group(function () {
            Route::get('/', [VacanteController::class, 'index'])->name('index');
            Route::get('exportar-excel', [VacanteController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [VacanteController::class, 'exportarPdf'])->name('exportarPdf');
            Route::post('/', [VacanteController::class, 'store'])->name('store');
            Route::put('{vacante}', [VacanteController::class, 'update'])->name('update');
            Route::put('{vacante}/estado', [VacanteController::class, 'actualizarEstado'])->name('estado');
            Route::post('{vacante}/cubrir', [VacanteController::class, 'cubrir'])->name('cubrir');
            Route::delete('{vacante}', [VacanteController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('candidatos')->name('candidatos.')->group(function () {
            Route::get('/', [CandidatoController::class, 'index'])->name('index');
            Route::get('exportar-excel', [CandidatoController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [CandidatoController::class, 'exportarPdf'])->name('exportarPdf');
            Route::post('/', [CandidatoController::class, 'store'])->name('store');
            Route::get('{candidato}', [CandidatoController::class, 'show'])->name('show');
            Route::put('{candidato}', [CandidatoController::class, 'update'])->name('update');
            Route::post('{candidato}/cv', [CandidatoController::class, 'subirCv'])->name('cv');
            Route::get('{candidato}/cv/descargar', [CandidatoController::class, 'descargarCv'])->name('cv.descargar');
            Route::put('{candidato}/estado', [CandidatoController::class, 'actualizarEstado'])->name('estado');
            Route::post('{candidato}/seguimientos', [CandidatoController::class, 'agregarSeguimiento'])->name('seguimientos.store');
            Route::delete('{candidato}', [CandidatoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('altas')->name('altas.')->group(function () {
            Route::get('/', [AltaDigitalController::class, 'index'])->name('index');
            Route::get('exportar-excel', [AltaDigitalController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [AltaDigitalController::class, 'exportarPdf'])->name('exportarPdf');
            Route::post('/', [AltaDigitalController::class, 'store'])->name('store');
            Route::get('{alta}', [AltaDigitalController::class, 'show'])->name('show');
            Route::get('{alta}/foto', [AltaDigitalController::class, 'descargarFoto'])->name('foto');
            Route::get('{alta}/firma', [AltaDigitalController::class, 'descargarFirma'])->name('firma');
            Route::get('{alta}/documentos/{documento}', [AltaDigitalController::class, 'descargarDocumento'])->name('documentos.descargar');
            Route::post('{alta}/enviar', [AltaDigitalController::class, 'enviar'])->name('enviar');
            Route::put('{alta}/revisar', [AltaDigitalController::class, 'revisar'])->name('revisar');
            Route::post('{alta}/aprobar', [AltaDigitalController::class, 'aprobar'])->name('aprobar');
            Route::post('{alta}/rechazar', [AltaDigitalController::class, 'rechazar'])->name('rechazar');
            Route::post('{alta}/cancelar', [AltaDigitalController::class, 'cancelar'])->name('cancelar');
        });

        Route::prefix('plantillas')->name('plantillas.')->group(function () {
            Route::get('/', [PlantillaController::class, 'index'])->name('index');
            Route::get('exportar-excel', [PlantillaController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [PlantillaController::class, 'exportarPdf'])->name('exportarPdf');
            Route::post('/', [PlantillaController::class, 'store'])->name('store');
            Route::post('{plantilla}', [PlantillaController::class, 'update'])->name('update');
            Route::delete('{plantilla}', [PlantillaController::class, 'destroy'])->name('destroy');
        });

        // "Formatos" es la experiencia principal de RH operativo: formatos
        // oficiales fijos de MR. LANA, solo generar/descargar (docs/FORMATOS_OFICIALES.md).
        // El motor de plantillas DOCX editables (Rh\FormatoController) se
        // conserva intacto para uso avanzado (Solicitudes → "Generar
        // formato" via GenerarFormatoDialog.vue, y el catálogo bajo
        // "catalogo/*" para super_admin/rh_admin) — no se muestra como
        // catálogo principal a RH normal (ver docs/PLANTILLAS_FORMATOS.md).
        Route::prefix('formatos')->name('formatos.')->group(function () {
            Route::get('/', [FormatoOficialController::class, 'index'])->name('index');
            Route::prefix('catalogo')->name('catalogo.')->group(function () {
                Route::get('/', [FormatoController::class, 'index'])->name('index');
                Route::get('exportar-excel', [FormatoController::class, 'exportarExcel'])->name('exportarExcel');
                Route::get('exportar-pdf', [FormatoController::class, 'exportarPdf'])->name('exportarPdf');
            });
            Route::post('preview', [FormatoController::class, 'preview'])->name('preview');
            Route::post('/', [FormatoController::class, 'store'])->name('store');
            Route::get('{documento}/descargar', [FormatoController::class, 'descargar'])->name('descargar');
            Route::get('{documento}/descargar-pdf', [FormatoController::class, 'descargarPdf'])->name('descargar-pdf');
            Route::post('{documento}/subir-firmado', [FormatoController::class, 'subirFirmado'])->name('subir-firmado');
            Route::delete('{documento}', [FormatoController::class, 'destroy'])->name('destroy');
        });

        // Configurador visual de posiciones de datos sobre cada formato
        // oficial (docs/FORMATOS_OFICIALES.md) — solo rh_admin/super_admin.
        Route::prefix('formatos-oficiales')->name('formatos-oficiales.')->group(function () {
            Route::get('/', [FormatoOficialController::class, 'index'])->name('index');
            Route::get('{formato}', [FormatoOficialController::class, 'show'])->name('show');
            Route::get('{formato}/original', [FormatoOficialController::class, 'original'])->name('original');
            Route::post('{formato}/configuracion', [FormatoOficialController::class, 'guardarConfiguracion'])->name('configuracion');
            Route::post('{formato}/vista-previa-configuracion', [FormatoOficialController::class, 'previsualizarConfiguracion'])->name('vista-previa-configuracion');
            Route::post('{formato}/vista-previa', [FormatoOficialController::class, 'previsualizarGeneracion'])->name('vista-previa');
            Route::post('{formato}/generar', [FormatoOficialController::class, 'generar'])->name('generar');
            Route::get('generaciones/{generacion}/descargar', [FormatoOficialController::class, 'descargar'])->name('descargar');
            Route::get('generaciones/{generacion}/previsualizar', [FormatoOficialController::class, 'previsualizar'])->name('previsualizar');
            Route::post('generaciones/{generacion}/subir-firmado', [FormatoOficialController::class, 'subirFirmado'])->name('subir-firmado');
        });

        // La revisión de vacaciones vive en la bandeja unificada de abajo
        // (rh.solicitudes.*, tipo `vacaciones`) — el módulo suelto
        // `rh/vacaciones` (Rh\VacacionesController) no estaba enlazado en
        // ningún menú y RH nunca lo usaba para aprobar/rechazar. La API
        // móvil legacy (api/v1/rh/vacaciones/*) sigue viva aparte.
        Route::prefix('solicitudes')->name('solicitudes.')->group(function () {
            Route::get('/', [SolicitudController::class, 'index'])->name('index');
            Route::get('exportar-excel', [SolicitudController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [SolicitudController::class, 'exportarPdf'])->name('exportarPdf');
            Route::get('{solicitud}', [SolicitudController::class, 'show'])->name('show');
            Route::get('{solicitud}/documentos/{documento}/ver', [SolicitudController::class, 'verDocumento'])->name('documentos.ver');
            Route::post('{solicitud}/revisar', [SolicitudController::class, 'revisar'])->name('revisar');
            Route::post('{solicitud}/requerir-correccion', [SolicitudController::class, 'requerirCorreccion'])->name('requerir-correccion');
            Route::post('{solicitud}/aprobar', [SolicitudController::class, 'aprobar'])->name('aprobar');
            Route::post('{solicitud}/rechazar', [SolicitudController::class, 'rechazar'])->name('rechazar');
            Route::post('{solicitud}/cerrar', [SolicitudController::class, 'cerrar'])->name('cerrar');
            Route::patch('{solicitud}/estado', [SolicitudController::class, 'actualizarEstado'])->name('actualizar-estado');

            Route::prefix('{solicitud}/finiquito')->name('finiquito.')->group(function () {
                Route::post('calcular', [FiniquitoController::class, 'calcular'])->name('calcular');
                Route::post('recalcular', [FiniquitoController::class, 'recalcular'])->name('recalcular');
                Route::put('ajustes', [FiniquitoController::class, 'actualizarAjustes'])->name('ajustes');
                Route::post('revisar', [FiniquitoController::class, 'revisar'])->name('revisar');
                Route::post('generar-pdf', [FiniquitoController::class, 'generarPdf'])->name('generar-pdf');
                Route::get('descargar-pdf', [FiniquitoController::class, 'descargarPdf'])->name('descargar-pdf');
                Route::post('firmado', [FiniquitoController::class, 'subirFirmado'])->name('firmado');
            });
        });

        Route::prefix('reportes')->name('reportes.')->group(function () {
            Route::get('/', [ReporteRhController::class, 'index'])->name('index');
            Route::get('excel', [ReporteRhController::class, 'exportarExcel'])->name('excel');
            Route::get('pdf', [ReporteRhController::class, 'exportarPdf'])->name('pdf');
        });

        Route::prefix('cumpleanos')->name('cumpleanos.')->group(function () {
            Route::get('/', [CumpleanosController::class, 'index'])->name('index');
            Route::post('frases', [CumpleanosController::class, 'storeFrase'])->name('frases.store');
            Route::put('frases/{frase}', [CumpleanosController::class, 'updateFrase'])->name('frases.update');
            Route::delete('frases/{frase}', [CumpleanosController::class, 'destroyFrase'])->name('frases.destroy');
            Route::prefix('configuracion')->name('configuracion.')->group(function () {
                Route::get('/', [CumpleanosConfiguracionController::class, 'index'])->name('index');
                Route::post('fondo', [CumpleanosConfiguracionController::class, 'actualizarFondo'])->name('fondo.actualizar');
                Route::delete('fondo', [CumpleanosConfiguracionController::class, 'eliminarFondo'])->name('fondo.eliminar');
                Route::get('fondo/ver', [CumpleanosConfiguracionController::class, 'fondo'])->name('fondo.ver');
            });
            Route::get('{colaborador}/felicitacion', [CumpleanosController::class, 'felicitacion'])->name('felicitacion');
            Route::post('{colaborador}/felicitacion/generar', [CumpleanosController::class, 'generar'])->name('felicitacion.generar');
            Route::post('{colaborador}/felicitacion/regenerar', [CumpleanosController::class, 'regenerar'])->name('felicitacion.regenerar');
            Route::post('{colaborador}/felicitacion/previsualizar', [CumpleanosController::class, 'previsualizarFrase'])->name('felicitacion.previsualizar');
            Route::post('{colaborador}/felicitacion/confirmar-frase', [CumpleanosController::class, 'confirmarFrase'])->name('felicitacion.confirmar-frase');
            Route::get('{colaborador}/felicitacion/descargar', [CumpleanosController::class, 'descargar'])->name('felicitacion.descargar');
            Route::post('{colaborador}/felicitacion/enviar', [CumpleanosController::class, 'enviarManual'])->name('felicitacion.enviar');
        });
    });
});
