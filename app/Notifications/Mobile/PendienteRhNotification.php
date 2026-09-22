<?php

namespace App\Notifications\Mobile;

use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notificación genérica del ciclo laboral (contrato por firmar, contrato
 * por vencer, evaluación pendiente, documento por imprimir/enviar/recibir,
 * préstamo por autorizar, finiquito, recibo disponible...). Siempre lleva el
 * objeto relacionado (related_type/related_id) y la acción esperada para
 * que web/app puedan navegar directo al recurso. Mismo formato de payload
 * que el resto de notificaciones móviles (tipo/titulo/mensaje/type/resource_id).
 */
class PendienteRhNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(
        private readonly string $tipo,
        private readonly string $titulo,
        private readonly string $mensaje,
        private readonly ?string $relatedType = null,
        private readonly ?int $relatedId = null,
        private readonly ?string $accion = null,
        private readonly string $prioridad = 'media',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'mensaje' => $this->mensaje,
            'url' => null,
            'type' => $this->tipo,
            'resource_id' => $this->relatedId,
            'related_type' => $this->relatedType,
            'related_id' => $this->relatedId,
            'accion' => $this->accion,
            'prioridad' => $this->prioridad,
        ];
    }
}
