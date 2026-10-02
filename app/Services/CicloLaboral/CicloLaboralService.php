<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoReingreso;
use App\Enums\EstadoUsuario;
use App\Enums\EtapaCicloLaboral;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoBaja;
use App\Enums\TipoContratacion;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\OnboardingAvance;
use App\Models\OnboardingProceso;
use App\Models\Reingreso;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Carbon\CarbonInterface;

/**
 * Fuente única de "¿dónde está esta persona?". Deriva —sin duplicar datos—
 * la etapa, el estado, el progreso, el responsable, la siguiente acción, los
 * bloqueos, los pasos (stepper), las aprobaciones ("¿quién preautorizó?",
 * "¿RH ya autorizó?"), la timeline y las acciones permitidas para quien
 * consulta. Ninguna pantalla (web o app) inventa el estado: todas consumen
 * este DTO.
 *
 * DTO estable:
 * {persona, etapa, estado, progreso, responsable_actual, siguiente_accion,
 *  fecha_desde_estado, bloqueos[], pasos[], aprobaciones, timeline[], acciones_permitidas[]}
 *
 * @phpstan-type Accion array{clave: string, etiqueta: string, tipo: string}
 * @phpstan-type Detalle array{estado: array{clave: string, etiqueta: string}, responsable: array{rol: string, nombre: string|null}, siguiente: array{clave: string, etiqueta: string}|null, bloqueos: list<string>, acciones: list<Accion>, progreso: int, desde: CarbonInterface|null}
 */
class CicloLaboralService
{
    public function __construct(
        private readonly CandidatoWorkflowService $reclutamiento,
        private readonly AprobacionService $aprobaciones,
        private readonly TimelineService $timeline,
        private readonly ExpedienteService $expediente,
        private readonly ContratoLaboralService $contratos,
        private readonly OnboardingService $onboarding,
        private readonly CierreLaboralService $cierres,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly EvaluacionPeriodoPruebaService $evaluaciones,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function obtenerEstado(Candidato|Colaborador $persona, ?User $viewer = null, bool $conTimeline = true): array
    {
        return $persona instanceof Candidato
            ? $this->deCandidato($persona, $viewer, $conTimeline)
            : $this->deColaborador($persona, $viewer, $conTimeline);
    }

    /**
     * @return array<string, mixed>
     */
    private function deCandidato(Candidato $c, ?User $viewer, bool $conTimeline): array
    {
        $c->loadMissing(['puestoObjetivo:id,nombre', 'sucursal:id,nombre', 'responsableRh:id,name,apellidos,colaborador_id']);
        $estado = $c->estado;
        $orden = $estado->esSalida() ? $c->etapa_maxima : $estado->orden();
        $acciones = $viewer !== null ? $this->reclutamiento->accionesPermitidas($c, $viewer) : [];
        $principal = $this->primaria($acciones);

        $definicion = [
            'perfil' => ['Perfil', 1, 1],
            'entrevista' => ['Entrevista', 2, 2],
            'psicometricas' => ['Psicométricas', 3, 4],
            'socioeconomico' => ['Socioeconómico', 5, 5],
            'referencias' => ['Referencias', 6, 6],
            'autorizacion' => ['Autorización', 7, 9],
            'contratacion' => ['Contratación', 10, 11],
        ];
        $pasoActual = $estado->esSalida() ? null : $estado->paso();
        $pasos = [];

        foreach ($definicion as $clave => [$etiqueta, $inicio, $fin]) {
            $pasos[] = [
                'clave' => $clave,
                'etiqueta' => $etiqueta,
                'estado' => match (true) {
                    $estado === EstadoCandidato::Contratado => 'completado',
                    $pasoActual === $clave => 'actual',
                    $orden > $fin => 'completado',
                    $estado->esSalida() && $orden >= $inicio && $orden <= $fin => 'detenido',
                    default => 'pendiente',
                },
            ];
        }

        $bloqueos = [];

        if ($estado->esSalida()) {
            $bloqueos[] = sprintf('Proceso cerrado: %s%s', $estado->etiqueta(), $c->motivo_salida !== null ? " — {$c->motivo_salida}" : '');
        }

        if (in_array($estado, [EstadoCandidato::EntrevistaPendiente, EstadoCandidato::RevisionPsicometricas, EstadoCandidato::SocioeconomicoPendiente, EstadoCandidato::PreseleccionGerente], true)
            && $this->jerarquia->preautorizadorDeCandidato($c)['usuario'] === null) {
            $bloqueos[] = 'El candidato no tiene gerente asignado ni la sucursal tiene responsable capturado.';
        }

        $etapa = match (true) {
            $estado->esSalida() => EtapaCicloLaboral::Descartado,
            $estado === EstadoCandidato::EnContratacion, $estado === EstadoCandidato::Contratado => EtapaCicloLaboral::Contratacion,
            default => EtapaCicloLaboral::Reclutamiento,
        };

        $ultimoCambio = $c->ultimoCambioEstado()->first();

        return [
            'persona' => [
                'tipo' => 'candidato',
                'id' => $c->id,
                'candidato_id' => $c->id,
                'colaborador_id' => $c->colaborador_id,
                'nombre' => $c->nombreCompleto(),
                'puesto' => $c->puestoObjetivo?->nombre,
                'sucursal' => $c->sucursal?->nombre,
                'fuente' => $c->fuente,
            ],
            'etapa' => $this->etapa($etapa),
            'estado' => ['clave' => $estado->value, 'etiqueta' => $estado->etiqueta()],
            'progreso' => $estado === EstadoCandidato::Contratado ? 100 : (int) round(min($orden, 11) / 11 * 100),
            'responsable_actual' => $this->reclutamiento->responsableActual($c),
            'siguiente_accion' => $principal ?? $this->siguientePasoCandidato($estado),
            'fecha_desde_estado' => ($ultimoCambio !== null ? $ultimoCambio->fecha : $c->created_at)?->toIso8601String(),
            'bloqueos' => $bloqueos,
            'pasos' => $pasos,
            'aprobaciones' => $this->aprobaciones->resumen($c, ProcesoAprobacion::SeleccionCandidato),
            'timeline' => $conTimeline ? $this->timeline->de($c) : [],
            'acciones_permitidas' => $acciones,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deColaborador(Colaborador $colaborador, ?User $viewer, bool $conTimeline): array
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre', 'jefe:id,name,apellidos', 'user:id,colaborador_id,acceso_bloqueado_en']);

        $cierre = CierreLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos()))
            ->latest('id')
            ->first();
        $reingreso = Reingreso::query()->where('colaborador_abierto_id', $colaborador->id)->first();
        $contrato = $this->contratos->vigente($colaborador);
        $onboarding = $this->onboarding->procesoActual($colaborador);
        $evaluacion = $contrato !== null ? EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->first() : null;
        $alta = $colaborador->estado_alta;
        $dadoDeBaja = $colaborador->trashed() || $alta === EstadoAltaColaborador::Baja;

        $etapa = EtapaCicloLaboral::Activo;
        $detalle = null;
        $aprobaciones = null;

        if ($cierre !== null) {
            $etapa = EtapaCicloLaboral::Cierre;
            $detalle = $this->detalleCierre($cierre, $viewer);
            $aprobaciones = $this->aprobaciones->resumen($cierre, ProcesoAprobacion::CierreLaboral);
        } elseif ($reingreso !== null && $reingreso->estado === EstadoReingreso::RevisionRh) {
            $etapa = EtapaCicloLaboral::Reingreso;
            $detalle = $this->detalleReingreso($reingreso, $viewer);
            $aprobaciones = $this->aprobaciones->resumen($reingreso, ProcesoAprobacion::Reingreso);
        } elseif ($dadoDeBaja) {
            $etapa = EtapaCicloLaboral::Finalizado;
            $detalle = $this->detalleFinalizado($colaborador, $viewer);
        } elseif ($alta !== null && $alta->enContratacion()) {
            $etapa = EtapaCicloLaboral::Contratacion;
            $detalle = $this->detalleContratacion($colaborador);
        } elseif ($alta === EstadoAltaColaborador::EnOnboarding || $alta === EstadoAltaColaborador::PendienteActivacion) {
            $etapa = EtapaCicloLaboral::Onboarding;
            $detalle = $this->detalleOnboarding($colaborador, $onboarding, $viewer);
        } elseif ($contrato !== null && $contrato->tipo !== TipoContratacion::Indeterminado && $contrato->fecha_fin !== null) {
            $etapa = EtapaCicloLaboral::PeriodoPrueba;
            $detalle = $this->detallePeriodoPrueba($colaborador, $contrato->fecha_fin, $evaluacion, $viewer);
            $detalle['acciones'] = [...$detalle['acciones'], ...$this->accionesActivo($colaborador, $viewer)];
            $aprobaciones = $evaluacion !== null ? $this->aprobaciones->resumen($evaluacion, ProcesoAprobacion::PeriodoPrueba) : null;
        }

        $detalle ??= $this->detalleActivo($colaborador, $viewer);

        if ($aprobaciones === null && $colaborador->candidato_id !== null) {
            $candidato = Candidato::query()->where('id', $colaborador->candidato_id)->first();
            $aprobaciones = $candidato !== null ? $this->aprobaciones->resumen($candidato, ProcesoAprobacion::SeleccionCandidato) : null;
        }

        return [
            'persona' => [
                'tipo' => 'colaborador',
                'id' => $colaborador->id,
                'colaborador_id' => $colaborador->id,
                'candidato_id' => $colaborador->candidato_id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'jefe' => $colaborador->jefe?->nombreCompleto(),
                'estatus' => $colaborador->estatus->value,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            ],
            'etapa' => $this->etapa($etapa),
            'estado' => $detalle['estado'],
            'progreso' => $detalle['progreso'],
            'responsable_actual' => $detalle['responsable'],
            'siguiente_accion' => $this->primaria($detalle['acciones']) ?? $detalle['siguiente'],
            'fecha_desde_estado' => $detalle['desde']?->toIso8601String(),
            'bloqueos' => $detalle['bloqueos'],
            'pasos' => $this->pasosColaborador($etapa),
            'aprobaciones' => $aprobaciones,
            'cierre_id' => $cierre?->id,
            'reingreso_id' => $reingreso?->id,
            'onboarding_id' => $onboarding?->id,
            'evaluacion_id' => $evaluacion?->id,
            'contrato_id' => $contrato?->id,
            'timeline' => $conTimeline ? $this->timeline->de($colaborador) : [],
            'acciones_permitidas' => $detalle['acciones'],
        ];
    }

    /**
     * @return Detalle
     */
    private function detalleContratacion(Colaborador $colaborador): array
    {
        $alta = $colaborador->estado_alta ?? EstadoAltaColaborador::PendienteDocumentos;
        $documental = $this->expediente->estadoDocumental($colaborador);
        $bloqueos = [];

        foreach ($documental['documentos'] as $doc) {
            $estado = (string) $doc['estado'];

            if (in_array($estado, ['pendiente', 'rechazado', 'requiere_correccion', 'vencido'], true)) {
                $detalle = $estado === 'pendiente' ? 'sin cargar' : (is_string($doc['motivo_rechazo']) && $doc['motivo_rechazo'] !== '' ? $doc['motivo_rechazo'] : $estado);
                $bloqueos[] = sprintf('%s: %s', (string) $doc['nombre'], $detalle);
            }
        }

        $plantillas = TareaRh::query()
            ->whereNull('resuelta_en')
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('tipo', [TipoTarea::ContratoPendiente->value, TipoTarea::PlantillaFaltante->value])
            ->pluck('titulo');

        foreach ($plantillas as $titulo) {
            $bloqueos[] = (string) $titulo;
        }

        if ($colaborador->user === null) {
            $bloqueos[] = 'La persona aún no completa su registro con el QR de contratación.';
        }

        $responsable = match ($alta) {
            EstadoAltaColaborador::PendienteDocumentos => ['rol' => 'Colaborador (subir documentos)', 'nombre' => $colaborador->nombreCompleto()],
            EstadoAltaColaborador::DocumentacionEnRevision => ['rol' => 'Recursos Humanos (revisar documentos)', 'nombre' => null],
            EstadoAltaColaborador::PendienteContrato => ['rol' => 'Recursos Humanos (contratos)', 'nombre' => null],
            default => ['rol' => 'Gerente (impresión, firma y huella)', 'nombre' => $colaborador->jefe?->nombreCompleto()],
        };

        return [
            'estado' => ['clave' => $alta->value, 'etiqueta' => $alta->etiqueta()],
            'responsable' => $responsable,
            'siguiente' => match ($alta) {
                EstadoAltaColaborador::PendienteDocumentos => ['clave' => 'subir_documentos', 'etiqueta' => 'Cargar los documentos faltantes'],
                EstadoAltaColaborador::DocumentacionEnRevision => ['clave' => 'revisar_documentos', 'etiqueta' => 'RH revisa los documentos cargados'],
                EstadoAltaColaborador::PendienteContrato => ['clave' => 'generar_contratos', 'etiqueta' => 'Generar contratos (cargar plantillas faltantes)'],
                default => ['clave' => 'firmar_contratos', 'etiqueta' => 'Imprimir, firmar y recabar huella'],
            },
            'bloqueos' => $bloqueos,
            'acciones' => [],
            'progreso' => match ($alta) {
                EstadoAltaColaborador::PendienteDocumentos => 5 + (int) round($documental['porcentaje'] * 0.15),
                EstadoAltaColaborador::DocumentacionEnRevision => 20,
                EstadoAltaColaborador::PendienteContrato => 25,
                default => 30,
            },
            'desde' => $colaborador->updated_at,
        ];
    }

    /**
     * @return Detalle
     */
    private function detalleOnboarding(Colaborador $colaborador, ?OnboardingProceso $proceso, ?User $viewer): array
    {
        if ($proceso === null) {
            return [
                'estado' => ['clave' => 'pendiente', 'etiqueta' => 'Onboarding por iniciar'],
                'responsable' => ['rol' => 'Recursos Humanos', 'nombre' => null],
                'siguiente' => null,
                'bloqueos' => ['El onboarding no se ha abierto: revisa que los contratos estén firmados.'],
                'acciones' => [],
                'progreso' => 40,
                'desde' => null,
            ];
        }

        $proceso->loadMissing('avances');
        $refuerzo = $proceso->avances->contains(fn (OnboardingAvance $a) => $a->estado === EstadoAvanceOnboarding::RequiereRefuerzo);
        $porPresentar = $proceso->avances->contains(fn (OnboardingAvance $a) => $a->estado->permiteIntento());
        $esTitular = $viewer !== null && $viewer->colaborador_id === $colaborador->id;
        $enAlcance = $viewer !== null && $this->alcance->alcanzaColaborador($viewer, $colaborador) && ! $esTitular;
        $puedeEntregar = $enAlcance && $viewer->can(OnboardingService::PERMISO_ENTREGAR);
        $puedeGestionar = $enAlcance && $viewer->can(OnboardingService::PERMISO_GESTIONAR);
        $bloqueosCierre = $proceso->estado === EstadoOnboarding::EntregaActivos ? $this->onboarding->bloqueos($proceso) : [];

        $acciones = [];

        if ($puedeEntregar && $proceso->estado === EstadoOnboarding::EntregaActivos) {
            $acciones[] = ['clave' => 'entregar_activos', 'etiqueta' => 'Registrar entrega de activos', 'tipo' => 'primaria'];
        }

        if (($puedeEntregar || $puedeGestionar) && $proceso->estado === EstadoOnboarding::EntregaActivos && $bloqueosCierre === []) {
            $acciones[] = ['clave' => 'completar_onboarding', 'etiqueta' => 'Cerrar onboarding', 'tipo' => 'primaria'];
        }

        if ($puedeGestionar && $refuerzo) {
            $acciones[] = ['clave' => 'retroalimentar_onboarding', 'etiqueta' => 'Dar retroalimentación', 'tipo' => 'primaria'];
        }

        if ($esTitular && $porPresentar) {
            $acciones[] = ['clave' => 'presentar_evaluacion', 'etiqueta' => 'Presentar evaluación', 'tipo' => 'primaria'];
        }

        $total = max($proceso->avances->count(), 1);
        $aprobados = $proceso->avances->where('estado', EstadoAvanceOnboarding::Aprobado)->count();

        return [
            'estado' => ['clave' => $proceso->estado->value, 'etiqueta' => $proceso->estado->etiqueta()],
            'responsable' => match (true) {
                $refuerzo => ['rol' => 'Recursos Humanos (retroalimentación)', 'nombre' => null],
                $proceso->estado === EstadoOnboarding::EntregaActivos => ['rol' => 'Gerente (entrega de activos)', 'nombre' => $colaborador->jefe?->nombreCompleto()],
                default => ['rol' => 'Colaborador (inducción)', 'nombre' => $colaborador->nombreCompleto()],
            },
            'siguiente' => match (true) {
                $proceso->estado === EstadoOnboarding::Completado => null,
                $proceso->estado === EstadoOnboarding::EntregaActivos => ['clave' => 'entregar_activos', 'etiqueta' => 'Entregar activos y responsivas'],
                $refuerzo => ['clave' => 'retroalimentar_onboarding', 'etiqueta' => 'RH da retroalimentación y habilita reevaluación'],
                default => ['clave' => 'presentar_evaluacion', 'etiqueta' => 'Presentar la evaluación del módulo disponible'],
            },
            'bloqueos' => $refuerzo ? ['Hay un módulo con calificación menor al mínimo esperando refuerzo de RH.', ...$bloqueosCierre] : $bloqueosCierre,
            'acciones' => $acciones,
            'progreso' => 40 + (int) round($aprobados / $total * 20),
            'desde' => $proceso->updated_at,
        ];
    }

    /**
     * @return Detalle
     */
    private function detallePeriodoPrueba(Colaborador $colaborador, CarbonInterface $fin, ?EvaluacionPeriodoPrueba $evaluacion, ?User $viewer): array
    {
        $estado = match (true) {
            $evaluacion === null => ['clave' => 'activo', 'etiqueta' => sprintf('Periodo de prueba en curso · vence %s', $fin->format('d/m/Y'))],
            $evaluacion->estado === EstadoEvaluacionPrueba::Pendiente => ['clave' => 'evaluacion_pendiente', 'etiqueta' => 'Evaluación pendiente del jefe'],
            $evaluacion->estado === EstadoEvaluacionPrueba::Devuelta => ['clave' => 'evaluacion_devuelta', 'etiqueta' => 'Evaluación devuelta por RH'],
            $evaluacion->estado === EstadoEvaluacionPrueba::Capturada => ['clave' => 'autorizacion_rh_pendiente', 'etiqueta' => sprintf('Jefe recomienda %s · pendiente autorización RH', $evaluacion->recomienda_renovar ? 'renovar' : 'NO renovar')],
            default => ['clave' => $evaluacion->decision_renovar ? 'renovacion_autorizada' : 'no_renovacion_autorizada', 'etiqueta' => $evaluacion->decision_renovar ? 'Renovación autorizada' : 'No renovación autorizada'],
        };

        $responsable = match ($estado['clave']) {
            'evaluacion_pendiente', 'evaluacion_devuelta' => ['rol' => 'Jefe inmediato (evaluar)', 'nombre' => $colaborador->jefe?->nombreCompleto()],
            'autorizacion_rh_pendiente' => ['rol' => 'Recursos Humanos (autorización final)', 'nombre' => null],
            'renovacion_autorizada' => ['rol' => 'Gerente (firma del contrato indeterminado)', 'nombre' => $colaborador->jefe?->nombreCompleto()],
            default => ['rol' => '—', 'nombre' => null],
        };

        $acciones = [];

        if ($evaluacion !== null && $viewer !== null) {
            $esEvaluador = $viewer->colaborador_id !== null && in_array($viewer->colaborador_id, [$evaluacion->evaluador_colaborador_id, $colaborador->jefe_id], true);

            if ($evaluacion->estado->permiteCaptura() && $esEvaluador && $viewer->can('evaluaciones.capturar')) {
                $acciones[] = ['clave' => 'capturar_evaluacion', 'etiqueta' => 'Evaluar periodo de prueba', 'tipo' => 'primaria'];
            }

            if ($evaluacion->estado === EstadoEvaluacionPrueba::Capturada && $viewer->can(EvaluacionPeriodoPruebaService::PERMISO_AUTORIZAR) && $viewer->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH)) {
                $acciones[] = ['clave' => 'autorizar_evaluacion', 'etiqueta' => 'Autorización final de RH', 'tipo' => 'primaria'];
            }
        }

        $dias = (int) now()->startOfDay()->diffInDays($fin, false);

        return [
            'estado' => $estado,
            'responsable' => $responsable,
            'siguiente' => match ($estado['clave']) {
                'activo' => ['clave' => 'esperar_evaluacion', 'etiqueta' => sprintf('La evaluación se habilita %d días antes del vencimiento (faltan %d días)', (int) config('contratos.dias_aviso_vencimiento', 15), $dias)],
                'evaluacion_pendiente', 'evaluacion_devuelta' => ['clave' => 'capturar_evaluacion', 'etiqueta' => 'El jefe evalúa y recomienda'],
                'autorizacion_rh_pendiente' => ['clave' => 'autorizar_evaluacion', 'etiqueta' => 'RH autoriza la decisión final'],
                default => null,
            },
            'bloqueos' => [],
            'acciones' => $acciones,
            'progreso' => 75,
            'desde' => $evaluacion !== null ? $evaluacion->updated_at : $colaborador->activado_en,
        ];
    }

    /**
     * @return Detalle
     */
    private function detalleCierre(CierreLaboral $cierre, ?User $viewer): array
    {
        $cierre->loadMissing('colaborador.jefe');
        $estado = $cierre->estado;
        $orden = (int) array_search($estado, EstadoCierreLaboral::cases(), true);
        $total = count(EstadoCierreLaboral::abiertos());

        $responsable = match ($estado) {
            EstadoCierreLaboral::Solicitado => ['rol' => 'Superior operativo (preautorización)', 'nombre' => $this->aprobaciones->pendiente($cierre, ProcesoAprobacion::CierreLaboral)?->aprobadorColaborador?->nombreCompleto()],
            EstadoCierreLaboral::FiniquitoAutorizado => ['rol' => 'Regional de coordinación (programar pago)', 'nombre' => null],
            EstadoCierreLaboral::PagoProgramado, EstadoCierreLaboral::FiniquitoFirmado => ['rol' => 'Gerente (cita, firma y pago)', 'nombre' => $cierre->colaborador->jefe?->nombreCompleto()],
            default => ['rol' => 'Recursos Humanos', 'nombre' => null],
        };

        $bloqueos = [];

        if ($cierre->fecha_efectiva->isFuture()) {
            $bloqueos[] = sprintf('La baja no se ejecuta antes de la fecha efectiva (%s).', $cierre->fecha_efectiva->format('d/m/Y'));
        }

        $plantillas = TareaRh::query()
            ->whereNull('resuelta_en')
            ->where('relacionado_type', $cierre->getMorphClass())
            ->where('relacionado_id', $cierre->id)
            ->where('tipo', TipoTarea::PlantillaFaltante->value)
            ->pluck('titulo');

        foreach ($plantillas as $titulo) {
            $bloqueos[] = (string) $titulo;
        }

        return [
            'estado' => ['clave' => $estado->value, 'etiqueta' => sprintf('%s · %s', $cierre->tipo_baja->etiqueta(), $estado->etiqueta())],
            'responsable' => $responsable,
            'siguiente' => null,
            'bloqueos' => $bloqueos,
            'acciones' => $viewer !== null ? $this->cierres->accionesPermitidas($cierre, $viewer) : [],
            'progreso' => $total > 0 ? (int) round(min($orden + 1, $total) / $total * 100) : 0,
            'desde' => $cierre->updated_at,
        ];
    }

    /**
     * @return Detalle
     */
    private function detalleReingreso(Reingreso $reingreso, ?User $viewer): array
    {
        return [
            'estado' => ['clave' => 'revision_rh', 'etiqueta' => 'Reingreso en revisión de RH'],
            'responsable' => ['rol' => 'Recursos Humanos', 'nombre' => null],
            'siguiente' => ['clave' => 'decidir_reingreso', 'etiqueta' => 'Decidir si el reingreso es viable'],
            'bloqueos' => [],
            'acciones' => $viewer !== null && $viewer->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH)
                ? [['clave' => 'decidir_reingreso', 'etiqueta' => 'Decidir reingreso', 'tipo' => 'primaria']]
                : [],
            'progreso' => 10,
            'desde' => $reingreso->created_at,
        ];
    }

    /**
     * @return Detalle
     */
    private function detalleFinalizado(Colaborador $colaborador, ?User $viewer): array
    {
        $puedeReingreso = $viewer !== null && ($viewer->can(ReingresoService::PERMISO_GESTIONAR) || $viewer->can(ReingresoService::PERMISO_SOLICITAR));

        return [
            'estado' => ['clave' => 'baja', 'etiqueta' => $colaborador->fecha_baja !== null ? 'Baja desde '.$colaborador->fecha_baja->format('d/m/Y') : 'Baja'],
            'responsable' => ['rol' => '—', 'nombre' => null],
            'siguiente' => null,
            'bloqueos' => [],
            'acciones' => $puedeReingreso ? [['clave' => 'solicitar_reingreso', 'etiqueta' => 'Solicitar reingreso', 'tipo' => 'secundaria']] : [],
            'progreso' => 100,
            'desde' => $colaborador->fecha_baja,
        ];
    }

    /**
     * @return Detalle
     */
    private function detalleActivo(Colaborador $colaborador, ?User $viewer): array
    {
        return [
            'estado' => ['clave' => 'activo', 'etiqueta' => 'Activo · periodo de prueba superado'],
            'responsable' => ['rol' => '—', 'nombre' => null],
            'siguiente' => null,
            'bloqueos' => [],
            'acciones' => $this->accionesActivo($colaborador, $viewer),
            'progreso' => 100,
            'desde' => $colaborador->activado_en,
        ];
    }

    /**
     * @return list<Accion>
     */
    private function accionesActivo(Colaborador $colaborador, ?User $viewer): array
    {
        if ($viewer === null || $viewer->colaborador_id === $colaborador->id || $colaborador->estatus !== EstadoUsuario::Activo) {
            return [];
        }

        $puede = ($viewer->can(CierreLaboralService::PERMISO_SOLICITAR) || $viewer->can(CierreLaboralService::PERMISO_GESTIONAR))
            && ($this->alcance->alcanzaColaborador($viewer, $colaborador) || ($viewer->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($viewer->colaborador, $colaborador)));

        return $puede ? [['clave' => 'solicitar_baja', 'etiqueta' => 'Solicitar baja', 'tipo' => 'peligro']] : [];
    }

    /**
     * @param  list<Accion>  $acciones
     * @return array{clave: string, etiqueta: string}|null
     */
    private function primaria(array $acciones): ?array
    {
        foreach ($acciones as $accion) {
            if ($accion['tipo'] === 'primaria') {
                return ['clave' => $accion['clave'], 'etiqueta' => $accion['etiqueta']];
            }
        }

        return null;
    }

    /**
     * Stepper de la ficha del colaborador (la Etapa 5 no aparece).
     *
     * @return list<array{clave: string, etiqueta: string, estado: string}>
     */
    private function pasosColaborador(EtapaCicloLaboral $etapa): array
    {
        $actual = match ($etapa) {
            EtapaCicloLaboral::Contratacion => 1,
            EtapaCicloLaboral::Onboarding => 2,
            EtapaCicloLaboral::PeriodoPrueba => 3,
            EtapaCicloLaboral::Activo => 4,
            EtapaCicloLaboral::Cierre => 5,
            EtapaCicloLaboral::Finalizado => 6,
            default => 0,
        };

        $pasos = [];
        $definicion = [
            ['contratacion', 'Contratación'],
            ['onboarding', 'Onboarding'],
            ['periodo_prueba', 'Periodo de prueba'],
            ['activo', 'Activo'],
            ['cierre', 'Cierre'],
        ];

        foreach ($definicion as $indice => [$clave, $etiqueta]) {
            $numero = $indice + 1;
            $pasos[] = [
                'clave' => $clave,
                'etiqueta' => $etiqueta,
                'estado' => match (true) {
                    $numero < $actual => 'completado',
                    $numero === $actual => 'actual',
                    default => 'pendiente',
                },
            ];
        }

        return $pasos;
    }

    /**
     * @return array{clave: string, etiqueta: string, numero: int|null}
     */
    private function etapa(EtapaCicloLaboral $etapa): array
    {
        return ['clave' => $etapa->value, 'etiqueta' => $etapa->etiqueta(), 'numero' => $etapa->numero()];
    }

    /**
     * @return array{clave: string, etiqueta: string}|null
     */
    private function siguientePasoCandidato(EstadoCandidato $estado): ?array
    {
        return match ($estado) {
            EstadoCandidato::Recibidos => ['clave' => 'evaluar_perfil', 'etiqueta' => 'Reclutamiento revisa el apego al perfil'],
            EstadoCandidato::EntrevistaPendiente => ['clave' => 'registrar_entrevista', 'etiqueta' => 'El gerente realiza la entrevista'],
            EstadoCandidato::PsicometricasPendientes => ['clave' => 'registrar_resultados_psicometricas', 'etiqueta' => 'Reclutamiento envía link y registra resultados'],
            EstadoCandidato::RevisionPsicometricas => ['clave' => 'revisar_psicometricas', 'etiqueta' => 'El gerente revisa las psicométricas'],
            EstadoCandidato::SocioeconomicoPendiente => ['clave' => 'registrar_socioeconomico', 'etiqueta' => 'El gerente realiza el estudio socioeconómico'],
            EstadoCandidato::ReferenciasPendientes => ['clave' => 'concluir_referencias', 'etiqueta' => 'Validar referencias laborales'],
            EstadoCandidato::PreseleccionGerente => ['clave' => 'preautorizar', 'etiqueta' => 'El gerente preautoriza la contratación'],
            EstadoCandidato::AutorizacionRhPendiente => ['clave' => 'autorizar_rh', 'etiqueta' => 'RH da la autorización final'],
            EstadoCandidato::AutorizadoRh => ['clave' => 'iniciar_contratacion', 'etiqueta' => 'RH genera el QR de contratación'],
            EstadoCandidato::EnContratacion => ['clave' => 'contratacion', 'etiqueta' => 'Registro, expediente y contratos (Etapa 2)'],
            default => null,
        };
    }

    /**
     * Ficha completa del colaborador en su ciclo (web "Ciclo laboral" y
     * GET /api/v1/rh/colaboradores/{id}/ciclo): estado, onboarding,
     * documentos laborales con su siguiente paso físico, evaluación, cierre
     * y opciones. Quien la pide ya pasó la autorización de alcance.
     *
     * @return array<string, mixed>
     */
    public function ficha(Colaborador $colaborador, User $usuario): array
    {
        $estado = $this->obtenerEstado($colaborador, $usuario);
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
        $proceso = $this->onboarding->procesoActual($colaborador);
        $evaluacion = isset($estado['evaluacion_id']) ? EvaluacionPeriodoPrueba::query()->with(['contrato', 'colaborador'])->where('id', $estado['evaluacion_id'])->first() : null;
        $cierre = isset($estado['cierre_id']) ? CierreLaboral::query()->where('id', $estado['cierre_id'])->first() : null;
        $puedeOperarFisico = $usuario->can('documentos_laborales.operar_fisico');

        $documentos = GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (GeneratedDocument $d) => [
                'id' => $d->id,
                'titulo' => $d->titulo,
                'clave' => $d->clave_plantilla,
                'estado' => $d->estado_flujo?->value,
                'estado_etiqueta' => $d->estado_flujo?->etiqueta(),
                'requiere_huella' => (bool) $d->requiere_huella,
                'requiere_testigos' => (bool) $d->requiere_testigos,
                'acciones' => $puedeOperarFisico ? self::pasosFisicos($d->estado_flujo) : [],
            ])
            ->values()
            ->all();

        return [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            ],
            'ciclo' => $estado,
            'onboarding' => $proceso !== null ? $this->onboarding->aArray($proceso, $usuario) : null,
            'documentos' => $documentos,
            'evaluacion' => $evaluacion !== null ? $this->evaluaciones->aArray($evaluacion) : null,
            'cierre' => $cierre !== null ? $this->cierres->aArray($cierre, true, $usuario) : null,
            'opciones' => [
                'causas' => array_values(array_map(
                    fn (TipoBaja $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()],
                    array_filter(TipoBaja::cases(), fn (TipoBaja $t) => $usuario->can(CierreLaboralService::PERMISO_GESTIONAR) || in_array($t->value, (array) config('ciclo_laboral.cierre.causas_solicitables', []), true)),
                )),
                'criterios' => array_values(array_filter((array) config('contratos.criterios_evaluacion', []), 'is_string')),
            ],
        ];
    }

    /**
     * "Mi espacio" del colaborador (web Mi portal / Mis pendientes y app):
     * SOLO lo que le toca hacer o esperar, en lenguaje llano. Por decisión
     * del negocio la persona nunca ve los nombres internos de las etapas
     * (contratación, onboarding, periodo de prueba): RH le va dando cada
     * paso. Nunca incluye evaluaciones, recomendaciones ni decisiones sobre
     * su renovación.
     *
     * @return array{pendientes: list<array{clave: string, titulo: string, descripcion: string, tipo: string, accion: array{etiqueta: string, href: string, app: string}|null, detalle: list<string>}>, lecciones: list<array<string, mixed>>, documentos: array{requeridos: int, aprobados: int, faltantes: int}|null, todo_listo: bool}
     */
    public function misPendientes(Colaborador $colaborador, User $usuario): array
    {
        $pendientes = [];
        $alta = $colaborador->estado_alta;
        $enIngreso = $alta?->enContratacion() === true;
        $documental = $this->expediente->estadoDocumental($colaborador);

        // Solo lo que la PERSONA sube (document_types.aplica_alta): contrato
        // firmado, finiquito, etc. los genera y escanea Recursos Humanos.
        $tiposPropios = DocumentType::query()->where('aplica_alta', true)->pluck('clave')->all();
        $propios = collect($documental['documentos'])->filter(fn (array $d) => in_array($d['clave'], $tiposPropios, true));
        $estadosPorSubir = [EstadoDocumento::Pendiente->value, EstadoDocumento::Rechazado->value, EstadoDocumento::RequiereCorreccion->value, EstadoDocumento::Vencido->value];
        $porSubir = $propios->filter(fn (array $d) => in_array($d['estado'], $estadosPorSubir, true));
        $documental = [
            ...$documental,
            'requeridos' => $propios->count(),
            'aprobados' => $propios->where('estado', EstadoDocumento::Aprobado->value)->count(),
            'faltantes' => $porSubir->count(),
            'en_revision' => $propios->filter(fn (array $d) => in_array($d['estado'], [EstadoDocumento::EnRevision->value, EstadoDocumento::Cargado->value], true))->count(),
        ];

        // Documentos por subir o corregir.
        if ($documental['faltantes'] > 0) {
            $nombres = array_values($porSubir->pluck('nombre')->map(fn ($n) => (string) $n)->all());

            $pendientes[] = [
                'clave' => 'subir_documentos',
                'titulo' => 'Sube tus documentos',
                'descripcion' => $documental['faltantes'] === 1 ? 'Te falta 1 documento por subir o corregir.' : sprintf('Te faltan %d documentos por subir o corregir.', $documental['faltantes']),
                'tipo' => 'accion',
                'accion' => ['etiqueta' => 'Subir documentos', 'href' => route('mi-expediente'), 'app' => 'expediente'],
                'detalle' => $nombres,
            ];
        }

        if ($documental['en_revision'] > 0) {
            $pendientes[] = $this->espera('documentos_en_revision', 'Estamos revisando tus documentos', 'Recursos Humanos te avisará si hace falta corregir alguno.');
        }

        // Documentos para firmar en la app/portal.
        $porFirmar = GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado_flujo', EstadoFlujoDocumento::PendienteFirmaColaborador->value)
            ->get(['id', 'titulo']);

        if ($porFirmar->isNotEmpty()) {
            $pendientes[] = [
                'clave' => 'firmar_documentos',
                'titulo' => $porFirmar->count() === 1 ? 'Firma un documento' : sprintf('Firma %d documentos', $porFirmar->count()),
                'descripcion' => 'Revísalos con calma y fírmalos desde tu expediente.',
                'tipo' => 'accion',
                'accion' => ['etiqueta' => 'Revisar y firmar', 'href' => route('mi-expediente'), 'app' => 'documentos-laborales'],
                'detalle' => array_values($porFirmar->map(fn (GeneratedDocument $d) => (string) ($d->titulo ?? 'Documento'))->all()),
            ];
        }

        if ($enIngreso && $documental['completo'] && $porFirmar->isEmpty()) {
            $pendientes[] = $alta === EstadoAltaColaborador::PendienteFirma
                ? $this->espera('firma_en_sucursal', 'Firma de tus documentos en sucursal', 'Tu jefe te entregará tus documentos impresos para firmarlos.')
                : $this->espera('preparando_documentos', 'Estamos preparando tus documentos', 'Te avisaremos en cuanto estén listos.');
        }

        // Lecciones de bienvenida (solo mientras estén en curso).
        $lecciones = [];
        $proceso = $this->onboarding->procesoActual($colaborador);

        if ($proceso !== null && $proceso->estado !== EstadoOnboarding::Completado && $proceso->estado !== EstadoOnboarding::Cancelado) {
            $detalle = $this->onboarding->aArray($proceso, $usuario, true);

            foreach ($detalle['modulos'] as $m) {
                $lecciones[] = [
                    'avance_id' => $m['avance_id'],
                    'titulo' => $m['titulo'],
                    'descripcion' => $m['descripcion'],
                    'estado' => match ($m['estado']) {
                        EstadoAvanceOnboarding::Aprobado->value => 'aprobada',
                        EstadoAvanceOnboarding::Bloqueado->value => 'bloqueada',
                        EstadoAvanceOnboarding::RequiereRefuerzo->value => 'en_espera',
                        default => 'disponible',
                    },
                    'contenido_url' => $m['contenido_url'],
                    'contenido' => $m['contenido'],
                    'preguntas' => $m['preguntas'],
                    'puede_presentar' => $m['puede_presentar'],
                    'retroalimentacion' => $m['retroalimentacion'],
                    'calificacion' => $m['mejor_calificacion'],
                    'calificacion_minima' => $m['calificacion_minima'],
                ];
            }

            $disponibles = collect($lecciones)->where('estado', 'disponible')->count();

            if ($disponibles > 0) {
                $pendientes[] = [
                    'clave' => 'lecciones_bienvenida',
                    'titulo' => 'Responde tus lecciones de bienvenida',
                    'descripcion' => $disponibles === 1 ? 'Tienes 1 lección lista: revisa el material y responde sus preguntas.' : sprintf('Tienes %d lecciones listas: revisa el material y responde sus preguntas.', $disponibles),
                    'tipo' => 'accion',
                    'accion' => ['etiqueta' => 'Empezar', 'href' => '#lecciones', 'app' => 'lecciones'],
                    'detalle' => [],
                ];
            }

            if (collect($lecciones)->contains('estado', 'en_espera')) {
                $pendientes[] = $this->espera('leccion_retroalimentacion', 'Recursos Humanos revisará tu lección', 'Te dejará un comentario para que vuelvas a intentarlo.');
            }

            if ($proceso->estado === EstadoOnboarding::EntregaActivos) {
                $pendientes[] = $this->espera('entrega_equipo', 'Recibirás tu equipo de trabajo', 'Tu jefe te entregará uniforme y equipo, y firmarás de recibido.');
            }
        }

        return [
            'pendientes' => $pendientes,
            'lecciones' => $lecciones,
            'documentos' => $enIngreso || $documental['faltantes'] > 0
                ? ['requeridos' => $documental['requeridos'], 'aprobados' => $documental['aprobados'], 'faltantes' => $documental['faltantes']]
                : null,
            'todo_listo' => $pendientes === [] && $lecciones === [],
        ];
    }

    /**
     * @return array{clave: string, titulo: string, descripcion: string, tipo: string, accion: null, detalle: list<string>}
     */
    private function espera(string $clave, string $titulo, string $descripcion): array
    {
        return ['clave' => $clave, 'titulo' => $titulo, 'descripcion' => $descripcion, 'tipo' => 'espera', 'accion' => null, 'detalle' => []];
    }

    /**
     * Paso físico siguiente que el gerente/corporativo puede registrar sobre
     * un documento laboral. El original recibido se escanea al expediente
     * antes de archivarse (FlujoDocumentalService::archivar exige Escaneado).
     *
     * @return list<string>
     */
    public static function pasosFisicos(?EstadoFlujoDocumento $estado): array
    {
        return match ($estado) {
            EstadoFlujoDocumento::PendienteImpresion => ['imprimir'],
            EstadoFlujoDocumento::Impreso, EstadoFlujoDocumento::PendienteFirmaFisica => ['firma_fisica'],
            EstadoFlujoDocumento::FirmadoFisicamente => ['envio'],
            EstadoFlujoDocumento::EnviadoCorporativo => ['recepcion'],
            EstadoFlujoDocumento::RecibidoCorporativo => ['escaneo'],
            EstadoFlujoDocumento::Escaneado => ['archivar'],
            default => [],
        };
    }
}
