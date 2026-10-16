<?php

use App\Http\Controllers\Rh\ActaController;
use App\Http\Controllers\Rh\AltaDigitalController;
use App\Http\Controllers\Rh\CambioFotoController;
use App\Http\Controllers\Rh\CampanaReclutamientoController;
use App\Http\Controllers\Rh\CandidatoController;
use App\Http\Controllers\Rh\CelebracionRhController;
use App\Http\Controllers\Rh\CredencialesController;
use App\Http\Controllers\Rh\CumpleanosConfiguracionController;
use App\Http\Controllers\Rh\CumpleanosController;
use App\Http\Controllers\Rh\DivisionExpedienteHistoricoController;
use App\Http\Controllers\Rh\DocumentExtraccionController;
use App\Http\Controllers\Rh\EmployeeDocumentController;
use App\Http\Controllers\Rh\ExpedienteController;
use App\Http\Controllers\Rh\FiniquitoController;
use App\Http\Controllers\Rh\FormatoOficialController;
use App\Http\Controllers\Rh\ImportacionTrabajadoresController;
use App\Http\Controllers\Rh\IncorporacionInvitacionController;
use App\Http\Controllers\Rh\IntervencionCandidatoController;
use App\Http\Controllers\Rh\LoteNominaController;
use App\Http\Controllers\Rh\MigracionExpedientesController;
use App\Http\Controllers\Rh\NominaController;
use App\Http\Controllers\Rh\PerfilPuestoController;
use App\Http\Controllers\Rh\ReporteRhController;
use App\Http\Controllers\Rh\SolicitudController;
use App\Http\Controllers\Rh\VacanteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Solo la experiencia de "modo colaborador" ve su propio expediente por
    // esta ruta (Gate `modo-colaborador`, ver NavigationService) — una
    // cuenta operativa sin colaborador enlazado recibe 403.
    Route::get('mi-expediente', [ExpedienteController::class, 'miExpediente'])->name('mi-expediente')->middleware('can:modo-colaborador');
    Route::post('mi-expediente/foto', [ExpedienteController::class, 'subirFotoPropia'])->name('portal.foto')->middleware('can:modo-colaborador');

    // Mis recibos de nómina (modo colaborador): solo emitidos.
    Route::get('mis-recibos', [NominaController::class, 'misRecibos'])->name('portal.recibos')->middleware('can:modo-colaborador');
    Route::get('mis-recibos/{recibo}/pdf', [NominaController::class, 'pdf'])->name('portal.recibos.pdf');

    Route::prefix('rh')->name('rh.')->group(function () {
        // «Generar credenciales»: usuario + contraseña temporal, sin correo
        // (docs/AUTENTICACION.md). Permiso y alcance en CredencialesService.
        Route::post('colaboradores/{colaborador}/credenciales', [CredencialesController::class, 'store'])
            ->name('colaboradores.credenciales')->middleware('throttle:20,1');
        Route::post('colaboradores/{colaborador}/credenciales/correo', [CredencialesController::class, 'enviarCorreo'])
            ->name('colaboradores.credenciales.correo')->middleware('throttle:10,1');

        // Importador de salarios y vacaciones históricas (CLAUDE.md §21-36):
        // subir → analizar (preview) → revisar → aplicar.
        Route::prefix('importaciones')->name('importaciones.')->group(function () {
            Route::get('/', [ImportacionTrabajadoresController::class, 'index'])->name('index');
            Route::post('/', [ImportacionTrabajadoresController::class, 'analizar'])->name('analizar')->middleware('throttle:10,1');
            Route::get('{importacion}', [ImportacionTrabajadoresController::class, 'show'])->name('show');
            Route::post('{importacion}/aplicar', [ImportacionTrabajadoresController::class, 'aplicar'])->name('aplicar')->middleware('throttle:10,1');
            // Fila sin match exacto: RH asigna a mano o crea al colaborador.
            Route::post('{importacion}/filas/{fila}/asignar', [ImportacionTrabajadoresController::class, 'asignarColaborador'])->name('filas.asignar');
            Route::post('{importacion}/filas/{fila}/crear-colaborador', [ImportacionTrabajadoresController::class, 'crearColaborador'])->name('filas.crear-colaborador');
            // Auditoría de matches manuales (CLAUDE.md §67-71): confirmar la
            // asociación queda disponible aunque la importación ya esté
            // aplicada — nunca cambia datos, solo deja constancia de que RH
            // la revisó.
            Route::post('{importacion}/filas/{fila}/confirmar', [ImportacionTrabajadoresController::class, 'confirmarMatch'])->name('filas.confirmar');
            // Quitar un archivo analizado que ya no se aplicará (las aplicadas son historial).
            Route::delete('{importacion}', [ImportacionTrabajadoresController::class, 'destroy'])->name('destroy');
        });

        // Actas administrativas (PRO-ADP-08): levantar, formato oficial,
        // firma o negativa, anexos, seguimiento y cierre. Mismo ActaService
        // que la API móvil.
        Route::prefix('actas')->name('actas.')->group(function () {
            Route::get('/', [ActaController::class, 'index'])->name('index');
            Route::get('colaboradores', [ActaController::class, 'buscarColaboradores'])->name('colaboradores');
            Route::get('testigos', [ActaController::class, 'buscarTestigos'])->name('testigos')->middleware('throttle:60,1');
            Route::get('{acta}/vista-previa', [ActaController::class, 'vistaPrevia'])->name('vista-previa');
            Route::post('{acta}/entrega', [ActaController::class, 'entrega'])->name('entrega');
            Route::post('colaborador/{colaborador}', [ActaController::class, 'store'])->name('store');
            Route::get('{acta}', [ActaController::class, 'show'])->name('show');
            Route::put('{acta}', [ActaController::class, 'update'])->name('update');
            Route::post('{acta}/anexos', [ActaController::class, 'anexo'])->name('anexos.store')->middleware('throttle:20,1');
            Route::get('{acta}/anexos/{anexo}', [ActaController::class, 'descargarAnexo'])->name('anexos.show');
            Route::post('{acta}/negativa-firma', [ActaController::class, 'negativaFirma'])->name('negativa-firma');
            Route::post('{acta}/seguimiento', [ActaController::class, 'seguimiento'])->name('seguimiento');
            Route::post('{acta}/cerrar', [ActaController::class, 'cerrar'])->name('cerrar');
        });

        // Cambios de foto de perfil por revisar (FotoColaboradorService).
        Route::prefix('cambios-foto')->name('cambios-foto.')->group(function () {
            Route::get('/', [CambioFotoController::class, 'index'])->name('index');
            Route::get('{cambio}/propuesta', [CambioFotoController::class, 'propuesta'])->name('propuesta');
            Route::post('{cambio}/aprobar', [CambioFotoController::class, 'aprobar'])->name('aprobar');
            Route::post('{cambio}/rechazar', [CambioFotoController::class, 'rechazar'])->name('rechazar');
        });

        // Recibos de nómina quincenales (docs/NOMINA_QUINCENAL.md).
        Route::prefix('nomina')->name('nomina.')->group(function () {
            // Lotes: PREPARAR → REVISAR → EMITIR (LoteNominaService).
            Route::prefix('lotes')->name('lotes.')->group(function () {
                Route::get('/', [LoteNominaController::class, 'index'])->name('index');
                Route::post('/', [LoteNominaController::class, 'store'])->name('store')->middleware('throttle:10,1');
                Route::get('{lote}', [LoteNominaController::class, 'show'])->name('show');
                Route::get('{lote}/imprimir', [LoteNominaController::class, 'descargarImpresion'])->name('imprimir')->middleware('throttle:10,1');
                Route::get('{lote}/recibos/{recibo}/pdf', [LoteNominaController::class, 'pdf'])->name('recibos.pdf');
                Route::get('{lote}/recibos/{recibo}/conceptos', [LoteNominaController::class, 'conceptos'])->name('recibos.conceptos');
                Route::post('{lote}/emitir', [LoteNominaController::class, 'emitir'])->name('emitir');
                Route::post('{lote}/cancelar', [LoteNominaController::class, 'cancelar'])->name('cancelar');
                Route::post('{lote}/recalcular', [LoteNominaController::class, 'recalcular'])->name('recalcular');
            });

            Route::get('/', [NominaController::class, 'index'])->name('index');
            Route::post('preparar', [NominaController::class, 'preparar'])->name('preparar');
            Route::post('emitir', [NominaController::class, 'emitir'])->name('emitir');
            Route::post('masivo', [NominaController::class, 'masivo'])->name('masivo');
            Route::get('descargar', [NominaController::class, 'descargar'])->name('descargar');
            Route::put('recibos/{recibo}', [NominaController::class, 'actualizar'])->name('recibos.update');
            Route::post('recibos/{recibo}/emitir', [NominaController::class, 'emitirUno'])->name('recibos.emitir');
            Route::get('recibos/{recibo}/pdf', [NominaController::class, 'pdf'])->name('recibos.pdf');
            Route::post('recibos/{recibo}/reparar-pdf', [NominaController::class, 'repararPdf'])->name('recibos.reparar-pdf');
        });

        Route::prefix('expedientes')->name('expedientes.')->group(function () {
            Route::get('/', [ExpedienteController::class, 'index'])->name('index');
            Route::get('exportar-excel', [ExpedienteController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [ExpedienteController::class, 'exportarPdf'])->name('exportarPdf');
            // Migración inicial de colaboradores y expedientes históricos
            // (docs/MIGRACION_INICIAL_EXPEDIENTES.md). Antes de {colaborador}.
            Route::prefix('migracion-inicial')->name('migracion.')->group(function () {
                Route::get('/', [MigracionExpedientesController::class, 'index'])->name('index');
                Route::post('analizar', [MigracionExpedientesController::class, 'analizar'])->name('analizar')->middleware('throttle:10,1');
                Route::post('{migracion}/decidir', [MigracionExpedientesController::class, 'decidir'])->name('decidir');
                Route::post('{migracion}/aplicar', [MigracionExpedientesController::class, 'aplicar'])->name('aplicar');
                Route::get('{migracion}/estado', [MigracionExpedientesController::class, 'estado'])->name('estado');
                Route::get('{migracion}/reporte', [MigracionExpedientesController::class, 'reporte'])->name('reporte');
                Route::get('{migracion}/credenciales', [MigracionExpedientesController::class, 'credenciales'])->name('credenciales');
            });
            Route::get('historico/{historico}', [MigracionExpedientesController::class, 'verHistorico'])->name('historico.ver');
            Route::post('historico/{historico}/vincular', [MigracionExpedientesController::class, 'vincularHistorico'])->name('historico.vincular');
            // Extraccion automatica de datos personales del PDF histórico
            // (docs/DOCUMENT_EXTRACTION.md) — mismo flujo que
            // documentos/{documento}/extraccion, pero sobre ExpedienteHistorico.
            Route::prefix('historico/{historico}/extraccion')->name('historico.extraccion.')->group(function () {
                Route::get('/', [DocumentExtraccionController::class, 'showHistorico'])->name('show');
                Route::post('aplicar', [DocumentExtraccionController::class, 'aplicarHistorico'])->name('aplicar');
                Route::post('ignorar', [DocumentExtraccionController::class, 'ignorarHistorico'])->name('ignorar');
                Route::post('reprocesar', [DocumentExtraccionController::class, 'reprocesarHistorico'])->name('reprocesar');
            });
            Route::get('{colaborador}/extracciones-pendientes', [DocumentExtraccionController::class, 'resumen'])->name('extracciones-pendientes');
            // División del PDF histórico por checklist + datos faltantes
            // (docs/DIVISION_EXPEDIENTE_HISTORICO.md). Copias; el original no se toca.
            Route::post('historico/{historico}/dividir', [DivisionExpedienteHistoricoController::class, 'dividir'])->name('historico.dividir')->middleware('throttle:10,1');
            Route::prefix('division-historico')->name('division-historico.')->group(function () {
                Route::get('partes/{parte}', [DivisionExpedienteHistoricoController::class, 'verParte'])->name('partes.ver');
                Route::post('partes/{parte}/asignar', [DivisionExpedienteHistoricoController::class, 'asignarParte'])->name('partes.asignar');
                Route::post('{division}/datos/aplicar', [DivisionExpedienteHistoricoController::class, 'aplicarDato'])->name('datos.aplicar');
                Route::get('{colaborador}', [DivisionExpedienteHistoricoController::class, 'show'])->name('show')->withTrashed();
            });
            // withTrashed(): un colaborador dado de baja (soft-deleted, ver
            // ExpedienteController::darDeBaja()) debe poder seguir
            // abriéndose desde Expedientes -- es donde vive tanto la baja
            // administrativa directa como "Reactivar colaborador"; Usuarios
            // (Administracion\UsuarioController) solo administra la cuenta
            // de acceso (correo/roles/bloqueo), nunca la relación laboral.
            Route::get('{colaborador}', [ExpedienteController::class, 'show'])->name('show')->withTrashed();
            Route::post('{colaborador}/avisar-datos-faltantes', [ExpedienteController::class, 'avisarDatosFaltantes'])->name('avisar-datos-faltantes')->middleware('throttle:20,1');
            Route::get('{colaborador}/foto', [ExpedienteController::class, 'descargarFoto'])->name('foto')->withTrashed();
            Route::post('{colaborador}/foto', [ExpedienteController::class, 'subirFoto'])->name('foto.store');
            // Acuse de alta IMSS (indicador verde/naranja, no pendiente).
            Route::get('{colaborador}/alta-imss', [ExpedienteController::class, 'descargarAltaImss'])->name('alta-imss.show')->withTrashed();
            Route::post('{colaborador}/alta-imss', [ExpedienteController::class, 'subirAltaImss'])->name('alta-imss.store');
            Route::put('{colaborador}/datos-personales', [ExpedienteController::class, 'actualizarDatosPersonales'])->name('datos-personales.update');
            Route::put('{colaborador}/datos-laborales', [ExpedienteController::class, 'actualizarDatosLaborales'])->name('datos-laborales.update');
            Route::post('{colaborador}/recibos-nomina', [ExpedienteController::class, 'generarReciboNomina'])->name('recibos-nomina.store');
            Route::get('recibos-nomina/{recibo}/descargar', [ExpedienteController::class, 'descargarReciboNomina'])->name('recibos-nomina.descargar');
            Route::post('recibos-nomina/{recibo}/regenerar-pdf', [ExpedienteController::class, 'regenerarPdfReciboNomina'])->name('recibos-nomina.regenerar-pdf');
            Route::post('prestamos/{prestamo}/movimientos', [ExpedienteController::class, 'registrarPagoPrestamo'])->name('prestamos.movimientos.store');
            Route::post('prestamos/{prestamo}/activar', [ExpedienteController::class, 'activarPrestamo'])->name('prestamos.activar');
            Route::put('{colaborador}/avisos', [ExpedienteController::class, 'registrarAvisos'])->name('avisos.update');
            Route::delete('{colaborador}', [ExpedienteController::class, 'darDeBaja'])->name('dar-de-baja');
            Route::post('{colaborador}/reactivar', [ExpedienteController::class, 'reactivar'])->name('reactivar')->withTrashed();
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
        // Vacantes es 100% informativo (derivado de la plantilla): no admite
        // alta/edición/cobertura manual, ver App\Http\Controllers\Rh\VacanteController.
        Route::prefix('vacantes')->name('vacantes.')->group(function () {
            Route::get('/', [VacanteController::class, 'index'])->name('index');
            Route::get('exportar-excel', [VacanteController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [VacanteController::class, 'exportarPdf'])->name('exportarPdf');
        });

        // Descriptivos y perfiles de puesto: el deber ser del puesto + candidato ideal (RH edita).
        Route::prefix('perfiles-puesto')->name('perfiles-puesto.')->group(function () {
            Route::get('/', [PerfilPuestoController::class, 'index'])->name('index');
            Route::get('importar', [PerfilPuestoController::class, 'importacion'])->name('importacion');
            Route::post('importar', [PerfilPuestoController::class, 'importar'])->name('importar');
            Route::get('{puesto}/descriptivo/{descriptivo}/pdf', [PerfilPuestoController::class, 'pdf'])->name('pdf');
            Route::get('{puesto}', [PerfilPuestoController::class, 'show'])->name('show');
            Route::get('{puesto}/editar', [PerfilPuestoController::class, 'edit'])->name('edit');
            Route::put('{puesto}', [PerfilPuestoController::class, 'update'])->name('update');
        });

        Route::prefix('candidatos')->name('candidatos.')->group(function () {
            Route::get('/', [CandidatoController::class, 'index'])->name('index');
            Route::get('exportar-excel', [CandidatoController::class, 'exportarExcel'])->name('exportarExcel');
            Route::get('exportar-pdf', [CandidatoController::class, 'exportarPdf'])->name('exportarPdf');
            Route::post('/', [CandidatoController::class, 'store'])->name('store');
            // Bandeja de CVs (descargas de WhatsApp Web sincronizadas al NAS):
            // antes de '{candidato}' para que no la capture como id.
            Route::get('bandeja-cv', [CandidatoController::class, 'bandejaCv'])->name('bandeja-cv.index');
            Route::get('bandeja-cv/ver', [CandidatoController::class, 'verBandejaCv'])->name('bandeja-cv.ver');
            Route::post('{candidato}/cv/desde-bandeja', [CandidatoController::class, 'cvDesdeBandeja'])->name('cv.desde-bandeja');
            Route::get('{candidato}', [CandidatoController::class, 'show'])->name('show');
            Route::put('{candidato}', [CandidatoController::class, 'update'])->name('update');
            Route::post('{candidato}/cv', [CandidatoController::class, 'subirCv'])->name('cv');
            Route::get('{candidato}/cv/descargar', [CandidatoController::class, 'descargarCv'])->name('cv.descargar');
            Route::get('{candidato}/cv/previsualizar', [CandidatoController::class, 'previsualizarCv'])->name('cv.previsualizar');
            Route::put('{candidato}/estado', [CandidatoController::class, 'actualizarEstado'])->name('estado');
            Route::post('{candidato}/seguimientos', [CandidatoController::class, 'agregarSeguimiento'])->name('seguimientos.store');
            Route::get('{candidato}/evidencias/{evidencia}', [CandidatoController::class, 'descargarEvidencia'])->name('evidencias.descargar');
            Route::post('{candidato}/evidencias', [CandidatoController::class, 'agregarEvidencias'])->name('evidencias.store');
            // Workflow de reclutamiento (CandidatoWorkflowService, mismo que la API).
            Route::post('{candidato}/perfil', [CandidatoController::class, 'evaluarPerfil'])->name('perfil');
            Route::post('{candidato}/entrevista', [CandidatoController::class, 'registrarEntrevista'])->name('entrevista');
            Route::post('{candidato}/psicometricas/link', [CandidatoController::class, 'enviarPsicometricas'])->name('psicometricas.link');
            Route::post('{candidato}/psicometricas/resultados', [CandidatoController::class, 'resultadosPsicometricas'])->name('psicometricas.resultados');
            Route::post('{candidato}/psicometricas/revision', [CandidatoController::class, 'revisarPsicometricas'])->name('psicometricas.revision');
            Route::post('{candidato}/socioeconomico', [CandidatoController::class, 'registrarSocioeconomico'])->name('socioeconomico');
            // Cargar ≠ decidir: el responsable revisa el estudio cargado.
            Route::post('{candidato}/socioeconomico/revision', [CandidatoController::class, 'revisarSocioeconomico'])->name('socioeconomico.revision');
            Route::post('{candidato}/referencias', [CandidatoController::class, 'registrarReferencia'])->name('referencias.store');
            Route::post('{candidato}/referencias/concluir', [CandidatoController::class, 'concluirReferencias'])->name('referencias.concluir');
            Route::post('{candidato}/autorizar', [CandidatoController::class, 'autorizarRh'])->name('autorizar');
            Route::post('{candidato}/rechazar', [CandidatoController::class, 'rechazarRh'])->name('rechazar');
            Route::post('{candidato}/devolver', [CandidatoController::class, 'devolverRh'])->name('devolver');
            Route::post('{candidato}/descartar', [CandidatoController::class, 'descartar'])->name('descartar');
            Route::post('{candidato}/contratacion', [CandidatoController::class, 'iniciarContratacion'])->name('contratacion');
            // Intervención por jerarquía sobre un rechazo de RH (CLAUDE.md
            // §10-11): el gerente solicita, Regional o Dirección Comercial
            // decide — nunca otra fase del kanban.
            Route::post('{candidato}/intervencion', [IntervencionCandidatoController::class, 'solicitar'])->name('intervencion.solicitar');
            // Sin ruta para eliminar candidatos: borraba el CV del NAS (evidencia
            // del proceso). Un candidato que no sigue se cierra con «descartar».
        });

        Route::prefix('intervenciones')->name('intervenciones.')->group(function () {
            Route::post('{intervencion}/decidir', [IntervencionCandidatoController::class, 'decidir'])->name('decidir');
        });

        Route::prefix('campanas')->name('campanas.')->group(function () {
            Route::get('/', [CampanaReclutamientoController::class, 'index'])->name('index');
            Route::get('costos/exportar', [CampanaReclutamientoController::class, 'exportarCostos'])->name('costos.exportar');
            Route::get('reporte', [CampanaReclutamientoController::class, 'reporte'])->name('reporte');
            Route::get('reporte-pdf', [CampanaReclutamientoController::class, 'reportePdf'])->name('reporte-pdf');
            Route::get('reporte-excel', [CampanaReclutamientoController::class, 'reporteExcel'])->name('reporte-excel');
            Route::get('{campana}/reporte-pdf', [CampanaReclutamientoController::class, 'reporteCampanaPdf'])->name('reporte-campana-pdf');
            Route::post('/', [CampanaReclutamientoController::class, 'store'])->name('store');
            Route::put('{campana}', [CampanaReclutamientoController::class, 'update'])->name('update');
            Route::delete('{campana}', [CampanaReclutamientoController::class, 'destroy'])->name('destroy');
            Route::get('{campana}/adjuntos/{adjunto}', [CampanaReclutamientoController::class, 'adjunto'])->name('adjuntos.show');
            Route::delete('{campana}/adjuntos/{adjunto}', [CampanaReclutamientoController::class, 'eliminarAdjunto'])->name('adjuntos.destroy');
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

        // MOTOR ANTERIOR DE FORMATOS (plantillas DOCX editables y formatos
        // oficiales versionados) — RETIRADO. Ya no crea, edita, publica ni
        // genera nada: la única fuente de formatos es Documentos maestros y
        // la generación vive en Documentos del proceso. Solo se conservan:
        //  - las pantallas viejas como redirección (marcadores/enlaces guardados);
        //  - descargar/previsualizar los formatos oficiales YA generados (los
        //    enlaza el expediente: ExpedienteController::documentosOficiales) y
        //    subir su firmado (evidencia de lo que generó la aprobación de una
        //    solicitud, SolicitudFormatoOficialService).
        // Cualquier otra ruta de estos prefijos responde 410 (Gone).
        Route::prefix('plantillas')->name('plantillas.')->group(function () {
            Route::get('/', fn () => redirect()->route('rh.documentos-maestros.index'))->name('index');
            Route::any('{retirado?}', fn () => abort(410, 'Este formato se retiró; usa Documentos del proceso.'))->where('retirado', '.*')->name('retirado');
        });

        Route::prefix('formatos')->name('formatos.')->group(function () {
            Route::get('/', fn () => redirect()->route('rh.documentos-maestros.index'))->name('index');
            Route::get('catalogo', fn () => redirect()->route('rh.documentos-maestros.index'))->name('catalogo.index');
            Route::any('{retirado?}', fn () => abort(410, 'Este formato se retiró; usa Documentos del proceso.'))->where('retirado', '.*')->name('retirado');
        });

        Route::prefix('formatos-oficiales')->name('formatos-oficiales.')->group(function () {
            Route::get('/', fn () => redirect()->route('rh.documentos-maestros.index'))->name('index');
            Route::get('generados', fn () => redirect()->route('rh.documentos-maestros.index'))->name('generados');
            Route::get('variables', fn () => redirect()->route('rh.documentos-maestros.index'))->name('variables');
            Route::get('nuevo', fn () => redirect()->route('rh.documentos-maestros.index'))->name('create');

            Route::get('generaciones/{generacion}/descargar', [FormatoOficialController::class, 'descargar'])->name('descargar');
            Route::get('generaciones/{generacion}/previsualizar', [FormatoOficialController::class, 'previsualizar'])->name('previsualizar');
            Route::post('generaciones/{generacion}/subir-firmado', [FormatoOficialController::class, 'subirFirmado'])->name('subir-firmado');

            Route::get('{formato}', fn () => redirect()->route('rh.documentos-maestros.index'))->whereNumber('formato')->name('show');
            Route::any('{retirado?}', fn () => abort(410, 'Este formato se retiró; usa Documentos del proceso.'))->where('retirado', '.*')->name('retirado');
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
            Route::get('{solicitud}/permiso-pdf', [SolicitudController::class, 'permisoPdf'])->name('permiso-pdf');
            Route::get('{solicitud}/documentos/{documento}/ver', [SolicitudController::class, 'verDocumento'])->name('documentos.ver');
            Route::post('{solicitud}/revisar', [SolicitudController::class, 'revisar'])->name('revisar');
            Route::post('{solicitud}/requerir-correccion', [SolicitudController::class, 'requerirCorreccion'])->name('requerir-correccion');
            Route::post('{solicitud}/visto-bueno', [SolicitudController::class, 'vistoBueno'])->name('visto-bueno');
            Route::post('{solicitud}/aprobar', [SolicitudController::class, 'aprobar'])->name('aprobar');
            Route::post('{solicitud}/rechazar', [SolicitudController::class, 'rechazar'])->name('rechazar');
            Route::patch('{solicitud}/estado', [SolicitudController::class, 'actualizarEstado'])->name('actualizar-estado');

            Route::prefix('{solicitud}/finiquito')->name('finiquito.')->group(function () {
                Route::post('calcular', [FiniquitoController::class, 'calcular'])->name('calcular');
                Route::post('recalcular', [FiniquitoController::class, 'recalcular'])->name('recalcular');
                Route::put('ajustes', [FiniquitoController::class, 'actualizarAjustes'])->name('ajustes');
                Route::post('conceptos', [FiniquitoController::class, 'agregarConcepto'])->name('conceptos.store');
                Route::patch('conceptos/{concepto}', [FiniquitoController::class, 'actualizarConcepto'])->name('conceptos.update');
                Route::delete('conceptos/{concepto}', [FiniquitoController::class, 'eliminarConcepto'])->name('conceptos.destroy');
                Route::post('conceptos-automaticos', [FiniquitoController::class, 'ajustarConcepto'])->name('conceptos.ajustar');
                Route::get('vista-previa', [FiniquitoController::class, 'vistaPrevia'])->name('vista-previa');
                Route::post('revisar', [FiniquitoController::class, 'revisar'])->name('revisar');
                Route::post('generar-pdf', [FiniquitoController::class, 'generarPdf'])->name('generar-pdf');
                Route::get('descargar-pdf', [FiniquitoController::class, 'descargarPdf'])->name('descargar-pdf');
                // Flujo vigente: firma en papel sin escaneo.
                Route::post('firmado-fisico', [FiniquitoController::class, 'marcarFirmadoFisicamente'])->name('firmado-fisico');
                // LEGACY: subir el escaneo firmado (registros antiguos).
                Route::post('firmado', [FiniquitoController::class, 'subirFirmado'])->name('firmado');
            });
        });

        Route::prefix('reportes')->name('reportes.')->group(function () {
            Route::get('/', [ReporteRhController::class, 'index'])->name('index');
            Route::get('excel', [ReporteRhController::class, 'exportarExcel'])->name('excel');
            Route::get('pdf', [ReporteRhController::class, 'exportarPdf'])->name('pdf');
        });

        // Aniversarios laborales y acciones comunes de celebraciones
        // (docs/CELEBRACIONES.md).
        Route::prefix('aniversarios')->name('aniversarios.')->group(function () {
            Route::get('/', [CelebracionRhController::class, 'aniversarios'])->name('index');
            Route::get('configuracion', [CelebracionRhController::class, 'configuracion'])->name('configuracion');
            Route::post('configuracion', [CelebracionRhController::class, 'guardarConfiguracion'])->name('configuracion.guardar');
            Route::get('configuracion/vista-previa', [CelebracionRhController::class, 'vistaPrevia'])->name('configuracion.vista-previa');
        });
        Route::prefix('celebraciones')->name('celebraciones.')->group(function () {
            Route::post('evento/{celebracion}/recepcion', [CelebracionRhController::class, 'recepcion'])->name('recepcion');
            Route::post('{colaborador}/{tipo}/enviar', [CelebracionRhController::class, 'enviar'])->name('enviar');
            Route::post('{colaborador}/{tipo}/avisar-todos', [CelebracionRhController::class, 'avisarATodos'])->name('avisar-todos');
            Route::post('{colaborador}/{tipo}/tarjeta', [CelebracionRhController::class, 'regenerarTarjeta'])->name('tarjeta.regenerar');
            Route::get('{colaborador}/{tipo}/tarjeta', [CelebracionRhController::class, 'descargarTarjeta'])->name('tarjeta');
        });

        Route::prefix('cumpleanos')->name('cumpleanos.')->group(function () {
            Route::get('/', [CumpleanosController::class, 'index'])->name('index');
            Route::post('frases', [CumpleanosController::class, 'storeFrase'])->name('frases.store');
            Route::put('frases/{frase}', [CumpleanosController::class, 'updateFrase'])->name('frases.update');
            Route::delete('frases/{frase}', [CumpleanosController::class, 'destroyFrase'])->name('frases.destroy');
            Route::prefix('configuracion')->name('configuracion.')->group(function () {
                Route::get('/', [CumpleanosConfiguracionController::class, 'index'])->name('index');
                Route::get('vista-previa', [CumpleanosConfiguracionController::class, 'vistaPrevia'])->name('vista-previa');
                Route::post('fondo', [CumpleanosConfiguracionController::class, 'actualizarFondo'])->name('fondo.actualizar');
                Route::delete('fondo', [CumpleanosConfiguracionController::class, 'eliminarFondo'])->name('fondo.eliminar');
                Route::get('fondo/ver', [CumpleanosConfiguracionController::class, 'fondo'])->name('fondo.ver');
                Route::put('apariencia', [CumpleanosConfiguracionController::class, 'actualizarApariencia'])->name('apariencia.actualizar');
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
