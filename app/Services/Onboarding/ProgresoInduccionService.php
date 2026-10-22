<?php

namespace App\Services\Onboarding;

use App\Enums\AccesoInduccionColaborador;
use App\Enums\EstadoAsignacionInduccion;
use App\Enums\EstadoEvaluacionInduccion;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\InduccionAsignacion;
use App\Models\OnboardingAvance;
use App\Models\OnboardingProceso;

/**
 * «Mi inducción»: el avance de la Inducción Mr. Lana en pasos —
 * Presentación → Video (o audio) → Evaluación → Resultado — en UN solo DTO
 * que usan Mi espacio (Portal/Index, Portal/MiProceso), la app (campo
 * aditivo `miInduccion`), la lista de asignaciones de RH y «Ver como
 * colaborador». Así lo que RH ve en la vista previa es exactamente lo que ve
 * la persona. Solo lectura: nunca registra avance.
 */
class ProgresoInduccionService
{
    public function __construct(
        private readonly AccesoInduccionColaboradorService $acceso,
        private readonly InduccionPdfService $pdf,
        private readonly MaterialInduccionService $materiales,
        private readonly ReproduccionVideoInduccionService $video,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resumen(Colaborador $colaborador): array
    {
        $proceso = $this->acceso->procesoInduccion($colaborador);
        $avance = $proceso !== null ? $this->acceso->avanceDeProceso($proceso) : null;

        return $this->armar($colaborador, $proceso, $avance);
    }

    /**
     * Lo que vería un colaborador NUEVO al que se le acaba de asignar: sin
     * avance, con la presentación y el material vigente.
     *
     * @return array<string, mixed>
     */
    public function resumenGenerico(): array
    {
        $acceso = AccesoInduccionColaborador::Disponible;
        $material = $this->datosMaterial(null, true);

        return $this->conPasos([
            'estado' => $acceso->value,
            'etiqueta' => $acceso->etiqueta(),
            'mensaje' => $acceso->mensaje(),
            'puede_ver_contenido' => true,
            'puede_contestar_evaluacion' => false,
            'estado_rh' => EstadoAsignacionInduccion::Asignada->value,
            'estado_rh_etiqueta' => EstadoAsignacionInduccion::Asignada->etiqueta(),
            'es_reinduccion' => false,
            'asignacion' => null,
            'evaluacion' => null,
        ], ['incluida' => true, 'total_paginas' => $this->pdf->totalPaginas(), 'paginas_vistas' => 0, 'porcentaje' => 0, 'completada' => false], $material, null, null, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function armar(Colaborador $colaborador, ?OnboardingProceso $proceso, ?OnboardingAvance $avance): array
    {
        $resumen = $this->acceso->resumen($colaborador);
        $asignacion = $avance !== null ? $this->acceso->asignacionVigente($avance) : null;
        $retirada = $avance !== null && $this->acceso->asignacionRetirada($avance);
        $incluye = $avance !== null ? $this->acceso->contenidoAsignado($avance) : ['presentacion' => true, 'material' => true];

        $progreso = (array) ($avance->progreso_contenido ?? []);
        $total = $this->pdf->totalPaginas();
        $vistas = count((array) ($progreso['paginas_vistas'] ?? []));
        $contenidoVisto = $avance?->contenido_visto_en !== null;
        $presentacionCompleta = $contenidoVisto || ($total > 0 && $vistas >= $total);

        $evaluacion = $proceso !== null
            ? EvaluacionInduccion::query()->where('onboarding_proceso_id', $proceso->id)->latest('id')->first()
            : null;

        $material = $this->datosMaterial($avance, $incluye['material']);
        $estadoRh = $this->estadoRh($avance, $retirada, $evaluacion, $vistas, $contenidoVisto, $material);

        return $this->conPasos([
            ...$resumen,
            'estado_rh' => $estadoRh->value,
            'estado_rh_etiqueta' => $estadoRh === EstadoAsignacionInduccion::Viendo && $total > 0
                ? sprintf('Viendo · %d%%', (int) floor(min($vistas, $total) / $total * 100))
                : $estadoRh->etiqueta(),
            'es_reinduccion' => $proceso?->esReinduccion() ?? false,
            'asignacion' => $this->datosAsignacion($asignacion, $avance),
            'evaluacion' => $evaluacion !== null ? [
                'id' => $evaluacion->id,
                'estado' => $evaluacion->estado->value,
                'etiqueta' => $evaluacion->estado->etiqueta(),
                'calificacion' => $evaluacion->ultima_calificacion !== null ? (float) $evaluacion->ultima_calificacion : null,
                'intentos' => $evaluacion->intentos_count,
                'fecha_limite_reevaluacion' => $evaluacion->fecha_limite_reevaluacion?->toDateString(),
            ] : null,
        ], [
            'incluida' => $incluye['presentacion'],
            'total_paginas' => $total,
            'paginas_vistas' => min($vistas, $total),
            'porcentaje' => $presentacionCompleta ? 100 : ($total > 0 ? (int) floor($vistas / $total * 100) : 0),
            'completada' => $presentacionCompleta,
        ], $material, $evaluacion?->estado, $evaluacion?->ultima_calificacion !== null ? (float) $evaluacion->ultima_calificacion : null, $contenidoVisto);
    }

    /**
     * @param  array{incluido: bool, tipo: string, tipo_etiqueta: string, obligatorio: bool, completado: bool, disponible: bool}|null  $material
     */
    private function estadoRh(?OnboardingAvance $avance, bool $retirada, ?EvaluacionInduccion $evaluacion, int $vistas, bool $contenidoVisto, ?array $material): EstadoAsignacionInduccion
    {
        if ($avance === null) {
            return EstadoAsignacionInduccion::SinAsignar;
        }

        if ($retirada) {
            return EstadoAsignacionInduccion::Retirada;
        }

        return match (true) {
            $evaluacion?->estado === EstadoEvaluacionInduccion::Aprobada => EstadoAsignacionInduccion::Aprobada,
            $evaluacion?->estado === EstadoEvaluacionInduccion::PendienteCalificacionRh => EstadoAsignacionInduccion::EnRevision,
            $evaluacion?->estado === EstadoEvaluacionInduccion::RequiereReforzamiento => EstadoAsignacionInduccion::Reforzamiento,
            $evaluacion !== null => EstadoAsignacionInduccion::EvaluacionPendiente,
            $contenidoVisto || ($material !== null && $material['incluido'] && $material['completado']) => EstadoAsignacionInduccion::MaterialVisto,
            $vistas > 0 => EstadoAsignacionInduccion::Viendo,
            default => EstadoAsignacionInduccion::Asignada,
        };
    }

    /**
     * @return array{incluido: bool, tipo: string, tipo_etiqueta: string, obligatorio: bool, completado: bool, disponible: bool}|null
     */
    private function datosMaterial(?OnboardingAvance $avance, bool $incluido): ?array
    {
        $modulo = $avance !== null ? $avance->modulo : $this->materiales->moduloInstitucional();
        $material = $modulo !== null ? $this->materiales->materialActivo($modulo) : null;

        if ($material === null) {
            return null;
        }

        $esVideo = $material->tipo->bloqueaEvaluacion();

        return [
            'incluido' => $incluido,
            'tipo' => $material->tipo->value,
            'tipo_etiqueta' => $esVideo ? 'Video' : 'Audio',
            'obligatorio' => $incluido && $esVideo,
            'completado' => $avance !== null && ($esVideo ? $this->video->videoCompletado($avance) : $avance->contenido_visto_en !== null),
            'disponible' => $material->recurso?->estaDisponible() ?? false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function datosAsignacion(?InduccionAsignacion $asignacion, ?OnboardingAvance $avance): ?array
    {
        if ($asignacion === null) {
            return $avance !== null ? [
                'automatica' => true,
                'incluye_presentacion' => true,
                'incluye_material' => true,
                'fecha_limite' => null,
                'vencida' => false,
                'mensaje' => null,
                'asignada_en' => $avance->created_at?->toIso8601String(),
                'asignada_por' => null,
            ] : null;
        }

        return [
            'automatica' => false,
            'incluye_presentacion' => $asignacion->incluye_presentacion,
            'incluye_material' => $asignacion->incluye_material,
            'fecha_limite' => $asignacion->fecha_limite?->toDateString(),
            'vencida' => $asignacion->fecha_limite !== null && $asignacion->fecha_limite->endOfDay()->isPast(),
            'mensaje' => $asignacion->mensaje,
            'asignada_en' => $asignacion->asignada_en->toIso8601String(),
            'asignada_por' => $asignacion->asignadaPor?->nombreCompleto(),
        ];
    }

    /**
     * Pasos del stepper (completado | actual | pendiente | detenido |
     * omitido) y el avance general.
     *
     * @param  array<string, mixed>  $datos
     * @param  array{incluida: bool, total_paginas: int, paginas_vistas: int, porcentaje: int, completada: bool}  $presentacion
     * @param  array{incluido: bool, tipo: string, tipo_etiqueta: string, obligatorio: bool, completado: bool, disponible: bool}|null  $material
     * @return array<string, mixed>
     */
    private function conPasos(array $datos, array $presentacion, ?array $material, ?EstadoEvaluacionInduccion $estadoEval, ?float $calificacion, bool $contenidoVisto): array
    {
        $datos = [...$datos, 'presentacion' => $presentacion, 'material' => $material, 'contenido_visto' => $contenidoVisto];

        $presentada = $estadoEval !== null && ! $estadoEval->permitePresentar();
        $materialIncluido = $material !== null && $material['incluido'];

        $pasos = [
            [
                'clave' => 'presentacion',
                'titulo' => 'Presentación',
                'detalle' => ! $presentacion['incluida']
                    ? 'No asignada'
                    : ($presentacion['completada'] ? 'Todas las hojas vistas' : sprintf('%d de %d hojas', $presentacion['paginas_vistas'], $presentacion['total_paginas'])),
                'hecho' => $presentacion['completada'] || $contenidoVisto,
                'omitido' => ! $presentacion['incluida'],
            ],
            [
                'clave' => 'material',
                'titulo' => $material !== null ? $material['tipo_etiqueta'] : 'Video',
                'detalle' => match (true) {
                    $material === null => 'Sin material cargado',
                    ! $material['incluido'] => 'No asignado',
                    $material['completado'] || $contenidoVisto => $material['tipo'] === 'video' ? 'Video completo' : 'Escuchado',
                    $material['tipo'] === 'video' => 'Velo completo para desbloquear tu evaluación',
                    default => 'Narración que acompaña las hojas',
                },
                'hecho' => $materialIncluido && ($material['completado'] || $contenidoVisto),
                'omitido' => ! $materialIncluido,
            ],
            [
                'clave' => 'evaluacion',
                'titulo' => 'Evaluación',
                'detalle' => match (true) {
                    $presentada => 'Contestada',
                    $estadoEval !== null => $estadoEval === EstadoEvaluacionInduccion::ReevaluacionHabilitada ? 'Vuelve a contestarla' : 'Lista para contestar',
                    default => 'Se desbloquea al terminar la inducción',
                },
                'hecho' => $presentada,
                'omitido' => false,
            ],
            [
                'clave' => 'resultado',
                'titulo' => 'Resultado',
                'detalle' => match ($estadoEval) {
                    EstadoEvaluacionInduccion::Aprobada => sprintf('Aprobada%s', $calificacion !== null ? sprintf(' · %s/30', rtrim(rtrim(number_format($calificacion, 1), '0'), '.')) : ''),
                    EstadoEvaluacionInduccion::PendienteCalificacionRh => 'Recursos Humanos la está calificando',
                    EstadoEvaluacionInduccion::RequiereReforzamiento => 'Reforzamiento con Recursos Humanos',
                    default => 'Al calificar tu evaluación',
                },
                'hecho' => $estadoEval === EstadoEvaluacionInduccion::Aprobada,
                'omitido' => false,
            ],
        ];

        $sinAsignar = in_array($datos['estado'], [AccesoInduccionColaborador::SinAsignar->value], true);
        $actualMarcado = false;
        $resultado = [];

        foreach ($pasos as $paso) {
            $estado = match (true) {
                $paso['omitido'] => 'omitido',
                $paso['hecho'] => 'completado',
                $sinAsignar => 'pendiente',
                $paso['clave'] === 'resultado' && $estadoEval === EstadoEvaluacionInduccion::RequiereReforzamiento => 'detenido',
                ! $actualMarcado => 'actual',
                default => 'pendiente',
            };

            if ($estado === 'actual' || $estado === 'detenido') {
                $actualMarcado = true;
            }

            $resultado[] = ['clave' => $paso['clave'], 'titulo' => $paso['titulo'], 'detalle' => $paso['detalle'], 'estado' => $estado];
        }

        $contables = array_values(array_filter($resultado, fn (array $p): bool => $p['estado'] !== 'omitido'));
        $hechos = count(array_filter($contables, fn (array $p): bool => $p['estado'] === 'completado'));

        return [
            ...$datos,
            'pasos' => $resultado,
            'porcentaje' => (int) round($hechos / max(1, count($contables)) * 100),
        ];
    }
}
