<?php

namespace App\Services\Contratos;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Control automático de vencimientos (lo ejecuta el scheduler vía
 * `contratos:revisar-vencimientos`, nunca depende de que alguien abra una
 * pantalla). N días antes del vencimiento (config contratos.dias_aviso_vencimiento,
 * 15 por defecto), por cada contrato vigente con fecha de fin:
 *
 *  - crea la evaluación de periodo de prueba (una por contrato: índice único),
 *  - abre las tareas "contrato por vencer" y "evaluación pendiente",
 *  - avisa al evaluador (regla "evaluacion_pendiente") y que ya se debe
 *    renovar el contrato (regla "contrato_por_vencer": RH, gerencia de su
 *    sucursal, su regional y la Gerencia de RH),
 *  - marca aviso_vencimiento_en para no volver a notificar.
 *
 * Idempotente: puede correr varias veces al día o en paralelo sin duplicar
 * evaluaciones, tareas ni notificaciones (bloqueo de fila + marca de aviso).
 */
class VencimientoContratosService
{
    public const PERMISO_RH = 'evaluaciones.autorizar';

    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @return array{revisados: int, avisados: int, errores: int}
     */
    public function revisar(): array
    {
        $dias = (int) config('contratos.dias_aviso_vencimiento', 15);
        $limite = now()->addDays($dias)->toDateString();

        $ids = ContratoLaboral::query()
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereNotNull('fecha_fin')
            ->whereNull('aviso_vencimiento_en')
            ->whereDate('fecha_fin', '<=', $limite)
            ->pluck('id');

        $avisados = 0;
        $errores = 0;

        foreach ($ids as $id) {
            try {
                if ($this->procesar((int) $id)) {
                    $avisados++;
                }
            } catch (Throwable $e) {
                $errores++;
                Log::error('VencimientoContratosService: no se pudo procesar el contrato.', ['contrato_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return ['revisados' => $ids->count(), 'avisados' => $avisados, 'errores' => $errores];
    }

    /**
     * true si en esta ejecución se creó el aviso (false si otra ejecución
     * ya lo había hecho).
     */
    public function procesar(int $contratoId): bool
    {
        $resultado = DB::transaction(function () use ($contratoId): ?EvaluacionPeriodoPrueba {
            $contrato = ContratoLaboral::query()->lockForUpdate()->find($contratoId);

            if ($contrato === null || $contrato->aviso_vencimiento_en !== null || $contrato->estado !== EstadoContratoLaboral::Vigente) {
                return null;
            }

            $contrato->loadMissing('colaborador');
            $colaborador = $contrato->colaborador;

            $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrCreate(
                ['contrato_laboral_id' => $contrato->id],
                [
                    'colaborador_id' => $colaborador->id,
                    'evaluador_colaborador_id' => $colaborador->jefe_id ?? $colaborador->gerente_id,
                    'estado' => EstadoEvaluacionPrueba::Pendiente,
                    'fecha_limite' => $contrato->fecha_fin?->toDateString(),
                ],
            );

            $contrato->update(['aviso_vencimiento_en' => now()]);

            return $evaluacion;
        });

        if ($resultado === null) {
            return false;
        }

        $this->avisar($resultado);

        return true;
    }

    private function avisar(EvaluacionPeriodoPrueba $evaluacion): void
    {
        $evaluacion->loadMissing(['contrato', 'colaborador.jefe.user', 'colaborador.gerente.user', 'evaluador.user']);
        $contrato = $evaluacion->contrato;
        $colaborador = $evaluacion->colaborador;
        $vence = $contrato->fecha_fin?->format('d/m/Y') ?? '';
        $nombre = $colaborador->nombreCompleto();
        $evaluadorUsuario = $evaluacion->evaluador?->user;

        $this->tareas->abrir(TipoTarea::ContratoPorVencer, $contrato, [
            'titulo' => "Contrato por vencer: {$nombre} ({$vence})",
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $colaborador,
            'permiso' => self::PERMISO_RH,
            'accion' => 'revisar_vencimiento',
            'vence_en' => $contrato->fecha_fin,
        ]);

        $this->tareas->abrir(TipoTarea::EvaluacionPendiente, $evaluacion, [
            'titulo' => "Evaluar periodo de prueba: {$nombre}",
            'descripcion' => "El contrato vence el {$vence}. Captura la evaluación y la recomendación de renovación.",
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $colaborador,
            'usuario' => $evaluadorUsuario,
            'permiso' => $evaluadorUsuario === null ? self::PERMISO_RH : null,
            'accion' => 'capturar_evaluacion',
            'vence_en' => $contrato->fecha_fin,
        ]);

        $mensaje = "El contrato de {$nombre} vence el {$vence}. La evaluación de periodo de prueba ya está habilitada.";

        // Evaluador (o, si no hay, su jefe/gerente según la regla).
        $avisados = $this->notificador->notificarEvento(
            'evaluacion_pendiente',
            $colaborador,
            ['evaluador' => $evaluadorUsuario],
            'Evaluación de periodo de prueba pendiente',
            $mensaje,
            $evaluacion,
            'capturar_evaluacion',
            'alta',
        );

        $colaborador->loadMissing('puesto');
        $duracion = '';

        if ($contrato->fecha_fin !== null) {
            $meses = max(1, (int) round($contrato->fecha_inicio->diffInMonths($contrato->fecha_fin->copy()->addDay())));
            $duracion = sprintf(' (contrato de %d %s%s)', $meses, $meses === 1 ? 'mes' : 'meses', $colaborador->puesto !== null ? ' como '.$colaborador->puesto->nombre : '');
        }

        // "Ya se debe renovar el contrato": por defecto RH con alcance y
        // SIEMPRE la gerencia de su sucursal, su regional y la Gerencia de
        // RH (regla "contrato_por_vencer"), sin repetir a quien ya recibió
        // el aviso de evaluar.
        $this->notificador->notificarEvento(
            'contrato_por_vencer',
            $colaborador,
            ['excluir' => array_values($avisados->map(fn ($usuario) => $usuario->id)->all())],
            'Ya se debe renovar el contrato',
            "El contrato de {$nombre}{$duracion} vence el {$vence}. Recuerden evaluar el periodo de prueba y decidir la renovación.",
            $contrato,
            'revisar_vencimiento',
            'alta',
        );

        $this->auditoria->registrar('contrato_aviso_vencimiento', $contrato, null, ['evaluacion_id' => $evaluacion->id, 'fecha_fin' => $contrato->fecha_fin?->toDateString()]);
    }
}
