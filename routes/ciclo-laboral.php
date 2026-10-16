<?php

use App\Http\Controllers\Portal\MiProcesoController;
use App\Http\Controllers\Rh\AcuseDescriptivoPuestoController;
use App\Http\Controllers\Rh\CicloColaboradorController;
use App\Http\Controllers\Rh\CierreLaboralController;
use App\Http\Controllers\Rh\DocumentoLaboralController;
use App\Http\Controllers\Rh\EvaluacionCapacitacionController;
use App\Http\Controllers\Rh\EvaluacionInduccionPdfController;
use App\Http\Controllers\Rh\EvaluacionPeriodoPruebaController;
use App\Http\Controllers\Rh\OnboardingController;
use App\Http\Controllers\Rh\PendienteController;
use App\Http\Controllers\Rh\ReingresoController;
use App\Http\Controllers\Rh\SesionInduccionController;
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
        // Inducción Mr. Lana: la presenta RH; aquí solo se VE durante la
        // sesión que incluye al colaborador (AccesoInduccionColaboradorService).
        Route::get('mi-proceso/induccion/ver', [MiProcesoController::class, 'verInduccion'])->name('portal.induccion.ver');
        Route::get('mi-proceso/induccion/capitulos/{avance}', [MiProcesoController::class, 'capitulosInduccion'])->name('portal.induccion.capitulos');
        Route::post('mi-proceso/induccion/capitulos/{avance}/pagina', [MiProcesoController::class, 'registrarPaginaInduccion'])->name('portal.induccion.pagina');
        Route::post('mi-proceso/induccion/capitulos/{avance}/terminar', [MiProcesoController::class, 'terminarInduccion'])->name('portal.induccion.terminar');
        Route::get('mi-proceso/induccion', [MiProcesoController::class, 'contenidoInduccion'])->name('portal.induccion.contenido');
        Route::post('mi-proceso/induccion/{evaluacion}/presentar', [MiProcesoController::class, 'presentarInduccion'])
            ->name('portal.induccion.presentar')
            ->middleware('throttle:10,1');
    });

    Route::prefix('rh')->name('rh.')->group(function () {
        Route::get('pendientes', [PendienteController::class, 'index'])->name('pendientes.index');

        Route::get('colaboradores/{colaborador}/ciclo', [CicloColaboradorController::class, 'show'])->name('colaboradores.ciclo')->withTrashed();
        Route::post('colaboradores/{colaborador}/cierres', [CierreLaboralController::class, 'store'])->name('colaboradores.cierres.store');
        // «Enviar descriptivo de puesto» tras aprobar la inducción.
        Route::post('colaboradores/{colaborador}/descriptivo-puesto/enviar', [AcuseDescriptivoPuestoController::class, 'enviar'])->name('colaboradores.descriptivo-puesto.enviar');

        Route::prefix('onboarding')->name('onboarding.')->group(function () {
            Route::get('configuracion', [OnboardingController::class, 'configuracion'])->name('configuracion');
            Route::post('modulos', [OnboardingController::class, 'guardarModulo'])->name('modulos.store');
            Route::put('modulos/{modulo}', [OnboardingController::class, 'actualizarModulo'])->name('modulos.update');
            Route::post('tipos-activo', [OnboardingController::class, 'guardarTipoActivo'])->name('tiposActivo.store');
            Route::put('tipos-activo/{tipoActivo}', [OnboardingController::class, 'actualizarTipoActivo'])->name('tiposActivo.update');
            Route::post('{proceso}/activos', [OnboardingController::class, 'entregarActivo'])->name('activos');
            Route::post('{proceso}/completar', [OnboardingController::class, 'completar'])->name('completar');
            Route::post('avances/{avance}/retroalimentacion', [OnboardingController::class, 'retroalimentar'])->name('retroalimentar');
            Route::get('induccion/intentos/{intento}', [OnboardingController::class, 'mostrarInduccion'])->name('induccion.mostrar');
            Route::post('induccion/intentos/{intento}/calificar', [OnboardingController::class, 'calificarInduccion'])->name('induccion.calificar');
            // PDF del formato FO-RH-EI (solo RH: lleva la clave de respuestas).
            Route::get('induccion/intentos/{intento}/pdf', [EvaluacionInduccionPdfController::class, 'vistaPrevia'])->name('induccion.pdf.vista');
            Route::get('induccion/intentos/{intento}/pdf/descargar', [EvaluacionInduccionPdfController::class, 'descargar'])->name('induccion.pdf.descargar');
            Route::post('induccion/intentos/{intento}/pdf/expediente', [EvaluacionInduccionPdfController::class, 'guardarEnExpediente'])->name('induccion.pdf.expediente');
            Route::post('induccion/{evaluacion}/retroalimentacion', [OnboardingController::class, 'retroalimentarInduccion'])->name('induccion.retroalimentar');
        });

        // Sesiones de presentación de la Inducción Mr. Lana: RH elige a quién
        // se la presenta, presenta y al terminar «Envía la evaluación».
        Route::prefix('induccion/sesiones')->name('induccion.sesiones.')->group(function () {
            Route::get('colaboradores', [SesionInduccionController::class, 'colaboradores'])->name('colaboradores');
            Route::post('/', [SesionInduccionController::class, 'store'])->name('store');
            Route::post('{sesion}/enviar', [SesionInduccionController::class, 'enviar'])->name('enviar');
            Route::post('{sesion}/cancelar', [SesionInduccionController::class, 'cancelar'])->name('cancelar');
        });

        Route::prefix('evaluaciones')->name('evaluaciones.')->group(function () {
            Route::get('/', [EvaluacionCapacitacionController::class, 'index'])->name('index');
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
            Route::post('envio', [DocumentoLaboralController::class, 'envio'])->name('envio');
            Route::post('recepcion', [DocumentoLaboralController::class, 'recepcion'])->name('recepcion');
            Route::post('escaneo', [DocumentoLaboralController::class, 'escaneo'])->name('escaneo');
            Route::post('archivar', [DocumentoLaboralController::class, 'archivar'])->name('archivar');
        });
    });
});
