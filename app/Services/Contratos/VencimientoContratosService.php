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
 *  - notifica al jefe inmediato y a RH,
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

        if ($evaluadorUsuario !== null) {
            $this->notificador->notificar([$evaluadorUsuario], 'evaluacion_pendiente', 'Evaluación de periodo de prueba pendiente', $mensaje, $evaluacion, 'capturar_evaluacion', 'alta');
        }

        $this->notificador->notificar(
            $this->notificador->responsablesDe($colaborador, self::PERMISO_RH),
            'contrato_por_vencer',
            'Contrato próximo a vencer',
            $mensaje,
            $contrato,
            'revisar_vencimiento',
            'alta',
        );

        $this->auditoria->registrar('contrato_aviso_vencimiento', $contrato, null, ['evaluacion_id' => $evaluacion->id, 'fecha_fin' => $contrato->fecha_fin?->toDateString()]);
    }
}
