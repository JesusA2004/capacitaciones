<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Enums\QuienContestaEvaluacionTecnica;
use App\Models\EvaluacionTecnica;
use App\Models\User;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos de las evaluaciones técnicas por la capa única
 * (NotificacionEventosService): campana web, bandeja y push. Un fallo al
 * avisar NUNCA deshace la evaluación: se registra en el log.
 */
class AvisosEvaluacionTecnicaService
{
    public function __construct(private readonly NotificacionEventosService $eventos) {}

    /** Quien debe contestarla: el colaborador, o su jefe directo del organigrama. */
    public function responsable(EvaluacionTecnica $evaluacion): ?User
    {
        $evaluacion->loadMissing(['colaborador.user', 'evaluador.user']);

        return $evaluacion->contesta === QuienContestaEvaluacionTecnica::Colaborador
            ? $evaluacion->colaborador?->user
            : $evaluacion->evaluador?->user;
    }

    public function pendiente(EvaluacionTecnica $evaluacion): void
    {
        $nombre = $evaluacion->colaborador?->nombreCompleto() ?? 'el colaborador';
        $limite = $evaluacion->fecha_limite->format('d/m/Y');

        [$titulo, $mensaje] = $evaluacion->contesta === QuienContestaEvaluacionTecnica::Colaborador
            ? [sprintf('Tu %s está lista', $evaluacion->nombre), sprintf('Ya puedes contestar tu evaluación técnica «%s». Tienes hasta el %s.', $evaluacion->nombre, $limite)]
            : [sprintf('%s por contestar', $evaluacion->nombre), sprintf('Evalúa a %s: «%s» (día %d desde su ingreso). Tienes hasta el %s.', $nombre, $evaluacion->nombre, $evaluacion->dias, $limite)];

        $this->enviar($evaluacion, new Aviso(
            'evaluacion_tecnica_pendiente', 'evaluacion_tecnica', $titulo, $mensaje, $evaluacion, 'contestar_evaluacion_tecnica',
            'media', ['colaborador_id' => $evaluacion->colaborador_id], null, null, true, 'Evaluación técnica pendiente',
        ));
    }

    public function vencida(EvaluacionTecnica $evaluacion): void
    {
        $nombre = $evaluacion->colaborador?->nombreCompleto() ?? 'el colaborador';
        $mensaje = $evaluacion->contesta === QuienContestaEvaluacionTecnica::Colaborador
            ? sprintf('Tu «%s» venció el %s y sigue sin contestarse. Contéstala hoy.', $evaluacion->nombre, $evaluacion->fecha_limite->format('d/m/Y'))
            : sprintf('La «%s» de %s venció el %s y sigue sin contestarse.', $evaluacion->nombre, $nombre, $evaluacion->fecha_limite->format('d/m/Y'));

        $this->enviar($evaluacion, new Aviso(
            'evaluacion_tecnica_vencida', 'evaluacion_tecnica', sprintf('%s vencida', $evaluacion->nombre), $mensaje, $evaluacion, 'contestar_evaluacion_tecnica',
            'alta', ['colaborador_id' => $evaluacion->colaborador_id], 'vencida', null, true, 'Evaluación técnica vencida',
        ));
    }

    /** Al jefe directo cuando su colaborador contestó su examen. */
    public function contestada(EvaluacionTecnica $evaluacion): void
    {
        if ($evaluacion->contesta !== QuienContestaEvaluacionTecnica::Colaborador) {
            return;
        }

        $evaluacion->loadMissing(['colaborador.jefe.user', 'colaborador.gerente.user']);
        $colaborador = $evaluacion->colaborador;
        $jefe = $colaborador->jefe->user ?? $colaborador->gerente->user ?? null;

        if ($jefe === null) {
            return;
        }

        try {
            $this->eventos->avisarResultado($jefe, new Aviso(
                'evaluacion_tecnica_contestada', 'evaluacion_tecnica',
                sprintf('%s contestó su %s', $colaborador?->nombreCompleto() ?? 'Tu colaborador', $evaluacion->nombre),
                sprintf('Resultado: %s. Revisa el detalle en su línea de tiempo de evaluaciones técnicas.', $evaluacion->aprobada === true ? 'aprobada' : ($evaluacion->aprobada === false ? 'no aprobada' : 'sin calificación')),
                $evaluacion, 'ver_evaluacion_tecnica', 'media', ['colaborador_id' => $evaluacion->colaborador_id], null, null, true, 'Evaluación técnica contestada',
            ));
        } catch (Throwable $e) {
            Log::warning('Evaluaciones técnicas: no se pudo avisar el resultado.', ['evaluacion' => $evaluacion->id, 'error' => $e->getMessage()]);
        }
    }

    private function enviar(EvaluacionTecnica $evaluacion, Aviso $aviso): void
    {
        $destinatario = $this->responsable($evaluacion);

        if ($destinatario === null) {
            Log::warning('Evaluaciones técnicas: la evaluación no tiene a quién avisar (sin cuenta o sin jefe directo en el organigrama).', [
                'evaluacion' => $evaluacion->id,
                'contesta' => $evaluacion->contesta->value,
            ]);

            return;
        }

        try {
            $this->eventos->crearPendiente([$destinatario], $aviso);
        } catch (Throwable $e) {
            Log::warning('Evaluaciones técnicas: no se pudo enviar el aviso.', ['evaluacion' => $evaluacion->id, 'evento' => $aviso->evento, 'error' => $e->getMessage()]);
        }
    }
}
