<?php

use App\Http\Controllers\Portal\DescriptivoPuestoController as PortalDescriptivoPuestoController;
use App\Http\Controllers\Portal\InduccionReproduccionController;
use App\Http\Controllers\Portal\MiProcesoController;
use App\Http\Controllers\Rh\AcuseDescriptivoPuestoController;
use App\Http\Controllers\Rh\AsignacionOnboardingController;
use App\Http\Controllers\Rh\CalificacionInduccionController;
use App\Http\Controllers\Rh\CausalRotacionController;
use App\Http\Controllers\Rh\CicloColaboradorController;
use App\Http\Controllers\Rh\CierreLaboralController;
use App\Http\Controllers\Rh\DocumentoLaboralController;
use App\Http\Controllers\Rh\EvaluacionCapacitacionController;
use App\Http\Controllers\Rh\EvaluacionInduccionPdfController;
use App\Http\Controllers\Rh\EvaluacionPeriodoPruebaController;
use App\Http\Controllers\Rh\MaterialInduccionController;
use App\Http\Controllers\Rh\OnboardingController;
use App\Http\Controllers\Rh\PendienteController;
use App\Http\Controllers\Rh\ReingresoController;
use App\Http\Controllers\Rh\SesionInduccionController;
use App\Http\Controllers\Rh\VistaColaboradorOnboardingController;
use App\Http\Controllers\Rh\VistaPreviaInduccionController;
use Illuminate\Support\Facades\Route;

/*
| Ciclo laboral (docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md): bandeja de
| pendientes, ciclo por persona, onboarding, periodo de prueba, cierre,
| reingreso y control del original físico. El workflow de candidatos vive
| en routes/rh.php. Cada controlador autoriza (Policy/permiso + alcance) y
| delega al MISMO Service que la API v1.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('can:modo-colaborador')->group(function () {
        Route::get('mi-proceso', [MiProcesoController::class, 'show'])->name('portal.mi-proceso');
        Route::post('mi-proceso/onboarding/{avance}/evaluacion', [MiProcesoController::class, 'evaluacion'])
            ->name('portal.onboarding.evaluacion')
            ->middleware('throttle:30,1');
        Route::post('mi-proceso/onboarding/{avance}/visto', [MiProcesoController::class, 'marcarContenidoVisto'])->name('portal.onboarding.visto');
        // Inducción Mr. Lana: el colaborador la ve por su cuenta mientras la
        // tiene asignada y por ver (AccesoInduccionColaboradorService).
        Route::get('mi-proceso/induccion/ver', [MiProcesoController::class, 'verInduccion'])->name('portal.induccion.ver');
        Route::get('mi-proceso/induccion/capitulos/{avance}', [MiProcesoController::class, 'capitulosInduccion'])->name('portal.induccion.capitulos');
        Route::post('mi-proceso/induccion/capitulos/{avance}/pagina', [MiProcesoController::class, 'registrarPaginaInduccion'])->name('portal.induccion.pagina');
        Route::post('mi-proceso/induccion/capitulos/{avance}/terminar', [MiProcesoController::class, 'terminarInduccion'])->name('portal.induccion.terminar');
        Route::get('mi-proceso/induccion', [MiProcesoController::class, 'contenidoInduccion'])->name('portal.induccion.contenido');
        Route::post('mi-proceso/induccion/{evaluacion}/presentar', [MiProcesoController::class, 'presentarInduccion'])
            ->name('portal.induccion.presentar')
            ->middleware('throttle:10,1');

        // Mi descriptivo de puesto: «Enterado(a)…» + «Estoy de acuerdo con…».
        Route::get('mi-proceso/descriptivo-puesto', [PortalDescriptivoPuestoController::class, 'show'])->name('portal.descriptivo-puesto');
        Route::post('mi-proceso/descriptivo-puesto/{acuse}/acusar', [PortalDescriptivoPuestoController::class, 'acusar'])
            ->name('portal.descriptivo-puesto.acusar')
            ->middleware('throttle:10,1');

        // Nuevo flujo: material de explicación (audio XOR video) que el
        // colaborador ve SOLO entre las hojas y su evaluación. El video tiene
        // anti-adelanto respaldado por el servidor (manifiesto/segmentos
        // firmados + heartbeat); el audio es narración continua.
        Route::get('mi-proceso/induccion/material/{avance}', [InduccionReproduccionController::class, 'material'])->name('portal.induccion.material');
        Route::post('mi-proceso/induccion/video/{avance}/iniciar', [InduccionReproduccionController::class, 'videoIniciar'])->name('portal.induccion.video.iniciar');
        Route::post('mi-proceso/induccion/video/{avance}/heartbeat', [InduccionReproduccionController::class, 'videoHeartbeat'])->name('portal.induccion.video.heartbeat');
        Route::post('mi-proceso/induccion/audio/{avance}/progreso', [InduccionReproduccionController::class, 'audioProgreso'])->name('portal.induccion.audio-progreso');

        Route::middleware('signed')->group(function () {
            Route::get('mi-proceso/induccion/video/{avance}/manifiesto/master.m3u8', [InduccionReproduccionController::class, 'manifiestoMaestro'])->name('portal.induccion.video.manifiesto-maestro');
            Route::get('mi-proceso/induccion/video/{avance}/manifiesto/{altura}.m3u8', [InduccionReproduccionController::class, 'variante'])->name('portal.induccion.video.variante');
            Route::get('mi-proceso/induccion/video/{avance}/segmento/{altura}/{archivo}', [InduccionReproduccionController::class, 'segmento'])->name('portal.induccion.video.segmento');
            Route::get('mi-proceso/induccion/audio/{avance}/stream', [InduccionReproduccionController::class, 'audio'])->name('portal.induccion.audio');
        });
    });

    Route::prefix('rh')->name('rh.')->group(function () {
        Route::get('pendientes', [PendienteController::class, 'index'])->name('pendientes.index');

        // Índices agregados del ciclo:
        //  · «3 · Onboarding» → Inducción al puesto (estado del acuse del
        //    descriptivo de puesto por colaborador en onboarding).
        //  · «7 · Cierres laborales» → Bajas y finiquitos (panorama de lectura;
        //    las acciones se ejecutan en la ficha, nunca las del flujo legacy).
        Route::get('induccion-puesto', [AcuseDescriptivoPuestoController::class, 'index'])->name('induccion-puesto.index');
        Route::get('cierres', [CierreLaboralController::class, 'index'])->name('cierres.index');
        // Bajas y finiquitos → «Causales de rotación»: subcatálogo (motivo)
        // de los tres tipos de baja. Sin borrado de las ya usadas.
        Route::prefix('causales-rotacion')->name('causales-rotacion.')->group(function () {
            Route::post('/', [CausalRotacionController::class, 'store'])->name('store');
            Route::post('grupos', [CausalRotacionController::class, 'grupoStore'])->name('grupos.store');
            Route::put('grupos/{grupo}', [CausalRotacionController::class, 'grupoUpdate'])->name('grupos.update');
            Route::patch('grupos/{grupo}/activo', [CausalRotacionController::class, 'grupoActivo'])->name('grupos.activo');
            Route::put('{causal}', [CausalRotacionController::class, 'update'])->name('update');
            Route::patch('{causal}/activo', [CausalRotacionController::class, 'activo'])->name('activo');
            Route::post('{causal}/mover', [CausalRotacionController::class, 'mover'])->name('mover');
        });

        Route::get('colaboradores/{colaborador}/ciclo', [CicloColaboradorController::class, 'show'])->name('colaboradores.ciclo')->withTrashed();
        Route::post('colaboradores/{colaborador}/cierres', [CierreLaboralController::class, 'store'])->name('colaboradores.cierres.store');
        // «Enviar descriptivo de puesto» tras aprobar la inducción.
        Route::post('colaboradores/{colaborador}/descriptivo-puesto/enviar', [AcuseDescriptivoPuestoController::class, 'enviar'])->name('colaboradores.descriptivo-puesto.enviar');
        // «Ver lo que recibirá»: PDF del descriptivo vigente antes de enviarlo.
        Route::get('colaboradores/{colaborador}/descriptivo-puesto/vista-previa', [AcuseDescriptivoPuestoController::class, 'vistaPrevia'])->name('colaboradores.descriptivo-puesto.vista-previa');

        Route::prefix('onboarding')->name('onboarding.')->group(function () {
            Route::get('configuracion', [OnboardingController::class, 'configuracion'])->name('configuracion');
            Route::post('modulos', [OnboardingController::class, 'guardarModulo'])->name('modulos.store');
            Route::put('modulos/{modulo}', [OnboardingController::class, 'actualizarModulo'])->name('modulos.update');
            Route::post('tipos-activo', [OnboardingController::class, 'guardarTipoActivo'])->name('tiposActivo.store');
            Route::put('tipos-activo/{tipoActivo}', [OnboardingController::class, 'actualizarTipoActivo'])->name('tiposActivo.update');
            Route::post('{proceso}/activos', [OnboardingController::class, 'entregarActivo'])->name('activos');
            Route::post('{proceso}/activos/vista-previa', [OnboardingController::class, 'vistaPreviaResponsiva'])->name('responsiva.vista-previa')->middleware('throttle:30,1');
            Route::post('{proceso}/completar', [OnboardingController::class, 'completar'])->name('completar');
            Route::post('avances/{avance}/retroalimentacion', [OnboardingController::class, 'retroalimentar'])->name('retroalimentar');
            Route::get('induccion/intentos/{intento}', [OnboardingController::class, 'mostrarInduccion'])->name('induccion.mostrar');
            Route::post('induccion/intentos/{intento}/calificar', [OnboardingController::class, 'calificarInduccion'])->name('induccion.calificar');
            Route::post('induccion/intentos/{intento}/recalificar', [OnboardingController::class, 'recalificarInduccion'])->name('induccion.recalificar');
            // PDF del formato FO-RH-EI (solo RH: lleva la clave de respuestas).
            Route::get('induccion/intentos/{intento}/pdf', [EvaluacionInduccionPdfController::class, 'vistaPrevia'])->name('induccion.pdf.vista');
            Route::get('induccion/intentos/{intento}/pdf/descargar', [EvaluacionInduccionPdfController::class, 'descargar'])->name('induccion.pdf.descargar');
            Route::post('induccion/intentos/{intento}/pdf/expediente', [EvaluacionInduccionPdfController::class, 'guardarEnExpediente'])->name('induccion.pdf.expediente');
            Route::post('induccion/{evaluacion}/retroalimentacion', [OnboardingController::class, 'retroalimentarInduccion'])->name('induccion.retroalimentar');

            // Material de explicación (audio XOR video) que el colaborador ve
            // solo. Reutiliza el pipeline multimedia SIN feature:capacitacion
            // (onboarding.gestionar lo autoriza en el controlador/Service).
            Route::post('induccion/material', [MaterialInduccionController::class, 'store'])->name('induccion.material.store')->middleware('throttle:20,1');
            Route::post('induccion/material/activar', [MaterialInduccionController::class, 'activar'])->name('induccion.material.activar');
            Route::delete('induccion/material', [MaterialInduccionController::class, 'destroy'])->name('induccion.material.destroy');

            // «Calificaciones y refuerzo»: resultados de la inducción (FO-RH-EI
            // + lecciones), retroalimentación y reevaluación.
            Route::get('calificaciones', [CalificacionInduccionController::class, 'index'])->name('calificaciones');

            // «Asignar a…» (todos / personas / sucursales) de la inducción y
            // del descriptivo de puesto, dentro del alcance del módulo.
            Route::get('asignar/colaboradores', [AsignacionOnboardingController::class, 'colaboradores'])->name('asignar.colaboradores');
            Route::get('asignar/sucursales', [AsignacionOnboardingController::class, 'sucursales'])->name('asignar.sucursales');
            Route::post('induccion/asignaciones/previsualizar', [AsignacionOnboardingController::class, 'previsualizarInduccion'])->name('induccion.asignaciones.previsualizar')->middleware('throttle:60,1');
            Route::post('induccion/asignaciones', [AsignacionOnboardingController::class, 'asignarInduccion'])->name('induccion.asignaciones.store')->middleware('throttle:20,1');
            Route::delete('induccion/asignaciones/{colaborador}', [AsignacionOnboardingController::class, 'retirarInduccion'])->name('induccion.asignaciones.destroy');
            Route::post('descriptivo-puesto/asignaciones/previsualizar', [AsignacionOnboardingController::class, 'previsualizarDescriptivo'])->name('descriptivo-puesto.asignaciones.previsualizar')->middleware('throttle:60,1');
            Route::post('descriptivo-puesto/asignaciones', [AsignacionOnboardingController::class, 'asignarDescriptivo'])->name('descriptivo-puesto.asignaciones.store')->middleware('throttle:20,1');

            // «Ver como colaborador» (solo lectura, no registra nada).
            Route::get('induccion/vista-colaborador/{colaborador?}', [VistaColaboradorOnboardingController::class, 'induccion'])->name('induccion.vista-colaborador');
            Route::get('descriptivo-puesto/vista-colaborador/{colaborador}', [VistaColaboradorOnboardingController::class, 'descriptivo'])->name('descriptivo-puesto.vista-colaborador');

            // Vistas previas de RH (tal cual lo verá el colaborador, sin
            // crear evaluación/intento ni registrar avance). Ver
            // Rh\VistaPreviaInduccionController.
            Route::get('induccion/vista-previa', [VistaPreviaInduccionController::class, 'evaluacion'])->name('induccion.vista-previa');
            Route::get('induccion/material/vista-previa', [VistaPreviaInduccionController::class, 'material'])->name('induccion.material.vista-previa.datos');
            Route::middleware('signed')->prefix('induccion/material/vista-previa')->name('induccion.material.vista-previa.')->group(function () {
                Route::get('video/master.m3u8', [VistaPreviaInduccionController::class, 'manifiestoMaestro'])->name('maestro');
                Route::get('video/{altura}.m3u8', [VistaPreviaInduccionController::class, 'variante'])->whereNumber('altura')->name('variante');
                Route::get('video/segmento/{altura}/{archivo}', [VistaPreviaInduccionController::class, 'segmento'])->whereNumber('altura')->name('segmento');
                Route::get('audio', [VistaPreviaInduccionController::class, 'audio'])->name('audio');
            });
        });

        // FLUJO LEGACY (feature:induccion_sesiones_legacy, apagado por defecto):
        // sesiones de presentación en vivo de la inducción. Se conservan para
        // historial/compatibilidad; con la bandera apagada responden
        // 403/Proximamente (EnsureFeatureEnabled). Ver docs/INDUCCION_NUEVO_FLUJO.md.
        Route::prefix('induccion/sesiones')->name('induccion.sesiones.')->middleware('feature:induccion_sesiones_legacy')->group(function () {
            Route::get('colaboradores', [SesionInduccionController::class, 'colaboradores'])->name('colaboradores');
            Route::post('/', [SesionInduccionController::class, 'store'])->name('store');
            Route::post('{sesion}/enviar', [SesionInduccionController::class, 'enviar'])->name('enviar');
            Route::post('{sesion}/cancelar', [SesionInduccionController::class, 'cancelar'])->name('cancelar');
        });

        Route::prefix('evaluaciones')->name('evaluaciones.')->group(function () {
            Route::get('/', [EvaluacionCapacitacionController::class, 'index'])->name('index');
            // «Asignar evaluación» (uno o varios) antes de que el scheduler la
            // abra sola; y el PDF para firma de una evaluación ya contestada
            // (inline = vista previa; ?descargar=1 = archivo).
            Route::post('asignar', [EvaluacionCapacitacionController::class, 'asignar'])->name('asignar')->middleware('throttle:20,1');
            Route::get('{evaluacion}/pdf', [EvaluacionCapacitacionController::class, 'pdf'])->name('pdf')->whereNumber('evaluacion')->middleware('throttle:30,1');
            Route::post('{evaluacion}/capturar', [EvaluacionPeriodoPruebaController::class, 'capturar'])->name('capturar');
            Route::post('{evaluacion}/autorizar', [EvaluacionPeriodoPruebaController::class, 'autorizar'])->name('autorizar');
            Route::post('{evaluacion}/devolver', [EvaluacionPeriodoPruebaController::class, 'devolver'])->name('devolver');
            // Excepción de Dirección General (FO-RH-EPP-001): nunca "forzar
            // aprobado" — RH/jefe la solicita, solo DG la resuelve.
            Route::post('{evaluacion}/excepcion', [EvaluacionPeriodoPruebaController::class, 'solicitarExcepcion'])->name('excepcion.solicitar');
            Route::post('excepciones/{solicitud}/resolver', [EvaluacionPeriodoPruebaController::class, 'resolverExcepcion'])->name('excepcion.resolver');
        });

        Route::prefix('cierres/{cierre}')->name('cierres.')->group(function () {
            // La baja se aplica al iniciarla (jefe directo o RH): ya no hay
            // preautorizar / autorizar / rechazar / devolver / reenviar.
            Route::post('aviso', [CierreLaboralController::class, 'aviso'])->name('aviso');
            Route::post('finiquito/calcular', [CierreLaboralController::class, 'calcularFiniquito'])->name('calcularFiniquito');
            // Finiquito: RH lo calcula, ajusta por concepto y registra su
            // entrega (cierra el cierre). Ya no existe «autorizar finiquito».
            Route::post('finiquito/conceptos-automaticos', [CierreLaboralController::class, 'ajustarConceptoFiniquito'])->name('finiquito.ajustar');
            Route::post('finiquito/conceptos', [CierreLaboralController::class, 'agregarConceptoFiniquito'])->name('finiquito.conceptos.store');
            Route::patch('finiquito/conceptos/{concepto}', [CierreLaboralController::class, 'actualizarConceptoFiniquito'])->name('finiquito.conceptos.update');
            Route::delete('finiquito/conceptos/{concepto}', [CierreLaboralController::class, 'eliminarConceptoFiniquito'])->name('finiquito.conceptos.destroy');
            Route::get('finiquito/vista-previa', [CierreLaboralController::class, 'vistaPreviaFiniquito'])->name('finiquito.vistaPrevia');
            Route::post('finiquito/entrega', [CierreLaboralController::class, 'entregarFiniquito'])->name('entregarFiniquito');
            // COMPATIBILIDAD LEGACY (flujo anterior del finiquito: programar
            // pago → cita → firma → confirmar pago). El flujo vigente cierra el
            // cierre con «Registrar entrega de finiquito»; CierreLaboralService
            // rechaza estos pasos (ValidationException) salvo con
            // features.cierre_flujo_legacy (CIERRE_FLUJO_LEGACY, apagado).
            Route::post('pago/programar', [CierreLaboralController::class, 'programarPago'])->name('programarPago');
            Route::post('cita', [CierreLaboralController::class, 'cita'])->name('cita');
            Route::post('finiquito/firmado', [CierreLaboralController::class, 'finiquitoFirmado'])->name('finiquitoFirmado');
            Route::post('pago/confirmar', [CierreLaboralController::class, 'confirmarPago'])->name('confirmarPago');
            Route::post('cerrar', [CierreLaboralController::class, 'cerrar'])->name('cerrar');
            Route::post('cancelar', [CierreLaboralController::class, 'cancelar'])->name('cancelar');
        });

        Route::prefix('reingresos')->name('reingresos.')->group(function () {
            Route::get('/', [ReingresoController::class, 'index'])->name('index');
            Route::post('/', [ReingresoController::class, 'store'])->name('store');
            Route::post('{reingreso}/decidir', [ReingresoController::class, 'decidir'])->name('decidir');
        });

        Route::prefix('documentos-laborales/{documento}')->name('documentos-laborales.')->group(function () {
            Route::get('descargar', [DocumentoLaboralController::class, 'descargar'])->name('descargar');
            Route::post('imprimir', [DocumentoLaboralController::class, 'imprimir'])->name('imprimir');
            Route::post('firma-fisica', [DocumentoLaboralController::class, 'firmaFisica'])->name('firmaFisica');
            // COMPATIBILIDAD LEGACY (flujo anterior: envío a corporativo →
            // recepción → escaneo). El flujo nuevo concluye en la firma física;
            // FlujoDocumentalService bloquea estos pasos sobre documentos nuevos
            // (solo cierran expedientes legacy o con features.documentos_flujo_legacy).
            Route::post('envio', [DocumentoLaboralController::class, 'envio'])->name('envio');
            Route::post('recepcion', [DocumentoLaboralController::class, 'recepcion'])->name('recepcion');
            Route::post('escaneo', [DocumentoLaboralController::class, 'escaneo'])->name('escaneo');
            Route::post('archivar', [DocumentoLaboralController::class, 'archivar'])->name('archivar');
        });
    });
});
