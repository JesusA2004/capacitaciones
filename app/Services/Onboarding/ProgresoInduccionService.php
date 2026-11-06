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
use App\Support\FechaMexico;

/**
 * «Mi inducción»: el avance de la Inducción Mr. Lana en pasos —
 * Presentación O Video (basta con uno de los dos; el audio solo acompaña) →
 * Evaluación → Resultado — en UN solo DTO que usan Mi espacio
 * (Portal/Index, Portal/MiProceso), la app (campo aditivo `miInduccion`),
 * la lista de asignaciones de RH y «Ver como colaborador». Así lo que RH ve en la vista previa es exactamente lo que ve
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

        $evaluacion = $proceso !== null
            ? $this->acceso->evaluacionDe($proceso)
            : null;

        $material = $this->datosMaterial($avance, $incluye['material']);
        // Presentación O video: si la terminó con el video, la presentación
        // no se da por vista (queda opcional en los pasos).
        $videoVisto = $material !== null && $material['incluido'] && $material['tipo'] === 'video' && $material['completado'];
        $hojasCompletas = $total > 0 && $vistas >= $total;
        $presentacionCompleta = $hojasCompletas || ($contenidoVisto && ! $videoVisto);
        $estadoRh = $this->estadoRh($avance, $retirada, $evaluacion, $vistas, $contenidoVisto || ($incluye['presentacion'] && $hojasCompletas), $material);

        $datos = $this->conPasos([
            ...$resumen,
            'estado_rh' => $estadoRh->value,
            'estado_rh_etiqueta' => $estadoRh->etiqueta(),
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

        // «Viendo · N %»: el MISMO avance general de la tarjeta (hojas y video
        // a medias cuentan), no solo las hojas de la presentación.
        if ($estadoRh === EstadoAsignacionInduccion::Viendo) {
            $datos['estado_rh_etiqueta'] = sprintf('Viendo · %d%%', $datos['porcentaje']);
        }

        return $datos;
    }

    /**
     * @param  array{incluido: bool, tipo: string, tipo_etiqueta: string, obligatorio: bool, completado: bool, disponible: bool, porcentaje: int, iniciado: bool}|null  $material
     */
    private function estadoRh(?OnboardingAvance $avance, bool $retirada, ?EvaluacionInduccion $evaluacion, int $vistas, bool $contenidoCumplido, ?array $material): EstadoAsignacionInduccion
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
            // Ya vio la presentación O el video (o terminó): solo le falta la evaluación.
            $contenidoCumplido || ($material !== null && $material['incluido'] && $material['completado']) => EstadoAsignacionInduccion::MaterialVisto,
            // Empezó si vio hojas O reprodujo parte del video/audio (una
            // reinducción de solo video no tiene hojas que contar).
            $vistas > 0 || ($material !== null && $material['incluido'] && $material['iniciado']) => EstadoAsignacionInduccion::Viendo,
            default => EstadoAsignacionInduccion::Asignada,
        };
    }

    /**
     * @return array{incluido: bool, tipo: string, tipo_etiqueta: string, obligatorio: bool, completado: bool, disponible: bool, porcentaje: int, iniciado: bool}|null
     */
    private function datosMaterial(?OnboardingAvance $avance, bool $incluido): ?array
    {
        $modulo = $avance !== null ? $avance->modulo : $this->materiales->moduloInstitucional();
        $material = $modulo !== null ? $this->materiales->materialActivo($modulo) : null;

        if ($material === null) {
            return null;
        }

        $esVideo = $material->tipo->bloqueaEvaluacion();
        $completado = $avance !== null && ($esVideo ? $this->video->videoCompletado($avance) : $avance->contenido_visto_en !== null);
        $porcentaje = match (true) {
            $avance === null => 0,
            $completado => 100,
            $esVideo => (int) floor($this->video->porcentajeRegistrado($avance)),
            default => $this->porcentajeAudio($avance, $material->recurso?->duracion_segundos),
        };

        return [
            'incluido' => $incluido,
            'tipo' => $material->tipo->value,
            'tipo_etiqueta' => $esVideo ? 'Video' : 'Audio',
            // Ya nunca es obligatorio: basta con la presentación O el video
            // (InduccionPdfService::materialVisto()). Se conserva la llave.
            'obligatorio' => false,
            'completado' => $completado,
            // Video: listo para reproducirse por HLS (no solo «disponible»).
            'disponible' => $esVideo ? $this->materiales->videoListo($material->recurso) : ($material->recurso?->estaDisponible() ?? false),
            'porcentaje' => $porcentaje,
            'iniciado' => $porcentaje > 0 || ($avance !== null && $esVideo && $this->video->videoIniciado($avance)),
        ];
    }

    /** Lo escuchado de la narración (progreso_contenido.audio.max_posicion / duración), 0–100. */
    private function porcentajeAudio(OnboardingAvance $avance, ?int $duracion): int
    {
        $audio = (array) (((array) ($avance->progreso_contenido ?? []))['audio'] ?? []);
        $maximo = (int) ($audio['max_posicion'] ?? 0);

        if ($duracion === null || $duracion < 1) {
            return 0;
        }

        return (int) floor(min(100, $maximo / $duracion * 100));
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
            // Día calendario de México (la app corre en UTC).
            'vencida' => $asignacion->fecha_limite !== null && $asignacion->fecha_limite->toDateString() < FechaMexico::hoy(),
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
     * @param  array{incluido: bool, tipo: string, tipo_etiqueta: string, obligatorio: bool, completado: bool, disponible: bool, porcentaje: int, iniciado: bool}|null  $material
     * @return array<string, mixed>
     */
    private function conPasos(array $datos, array $presentacion, ?array $material, ?EstadoEvaluacionInduccion $estadoEval, ?float $calificacion, bool $contenidoVisto): array
    {
        $datos = [...$datos, 'presentacion' => $presentacion, 'material' => $material, 'contenido_visto' => $contenidoVisto];

        $presentada = $estadoEval !== null && ! $estadoEval->permitePresentar();
        $materialIncluido = $material !== null && $material['incluido'];
        // Presentación O video (nunca los dos): con uno visto, el otro queda
        // opcional («omitido») y no detiene la evaluación.
        $esVideo = $materialIncluido && $material['tipo'] === 'video';
        $videoVisto = $esVideo && $material['completado'];
        $presentacionVista = $presentacion['incluida'] && $presentacion['completada'];
        $cumplido = $contenidoVisto || $presentacionVista || $videoVisto;
        // Con la evaluación ya habilitada (o contestada / aprobada) el
        // contenido quedó cubierto: presentación y video se marcan completos.
        $enEvaluacion = $estadoEval !== null;
        $cubierto = $presentacionVista ? 'Cubierto con la presentación' : ($videoVisto ? 'Cubierto con el video' : 'Cubierto: ya pasó a la evaluación');
        $alternativaVideo = $esVideo ? ' · o ve el video completo' : '';
        $alternativaHojas = $presentacion['incluida'] ? ' o termina la presentación' : '';

        $pasos = [
            [
                'clave' => 'presentacion',
                'titulo' => 'Presentación',
                'detalle' => match (true) {
                    ! $presentacion['incluida'] => 'No asignada',
                    $presentacion['completada'] => 'Todas las hojas vistas',
                    $enEvaluacion => $cubierto,
                    $videoVisto => 'Opcional: ya viste el video',
                    default => sprintf('%d de %d hojas%s', $presentacion['paginas_vistas'], $presentacion['total_paginas'], $alternativaVideo),
                },
                'hecho' => $presentacionVista || ($presentacion['incluida'] && $enEvaluacion),
                'omitido' => ! $presentacion['incluida'] || (! $presentacionVista && ! $enEvaluacion && $videoVisto),
            ],
            [
                'clave' => 'material',
                'titulo' => $material !== null ? $material['tipo_etiqueta'] : 'Video',
                'detalle' => match (true) {
                    $material === null => 'Sin material cargado',
                    ! $material['incluido'] => 'No asignado',
                    $videoVisto => 'Video completo',
                    $esVideo && $enEvaluacion => $cubierto,
                    $esVideo && $cumplido => 'Opcional: ya viste la presentación',
                    ! $esVideo && ($material['completado'] || $contenidoVisto) => 'Escuchado',
                    $esVideo && ! $material['disponible'] => 'El video se está preparando',
                    $esVideo && $material['porcentaje'] > 0 => sprintf('%d %% visto · velo completo%s para desbloquear tu evaluación', $material['porcentaje'], $alternativaHojas),
                    $esVideo => sprintf('Velo completo%s para desbloquear tu evaluación', $alternativaHojas),
                    default => 'Narración que acompaña las hojas',
                },
                'hecho' => $esVideo ? $videoVisto || $enEvaluacion : $materialIncluido && ($material['completado'] || $contenidoVisto || $enEvaluacion),
                'omitido' => ! $materialIncluido || ($esVideo && ! $videoVisto && ! $enEvaluacion && $cumplido),
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

        // Avance general: cada paso no omitido vale lo mismo; la presentación
        // y el video/audio suman su avance PARCIAL (hojas vistas, porcentaje
        // del video), así una reinducción de solo video no se queda en 0 %
        // mientras la persona lo está viendo.
        $parcial = [
            'presentacion' => $presentacion['porcentaje'] / 100,
            'material' => $material !== null ? $material['porcentaje'] / 100 : 0,
        ];
        $contables = array_values(array_filter($resultado, fn (array $p): bool => $p['estado'] !== 'omitido'));
        $avance = array_sum(array_map(
            fn (array $p): float => $p['estado'] === 'completado' ? 1.0 : (float) min(1, max(0, $parcial[$p['clave']] ?? 0)),
            $contables,
        ));

        return [
            ...$datos,
            'pasos' => $resultado,
            'porcentaje' => (int) round($avance / max(1, count($contables)) * 100),
        ];
    }
}
