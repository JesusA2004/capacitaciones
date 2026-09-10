<?php

namespace App\Notifications\Mobile;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DocumentoActualizadoNotification extends Notification implements ShouldQueue
{
    use BroadcastsNotificacion, Queueable;

    public function __construct(private readonly EmployeeDocument $documento) {}

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
            'tipo' => 'documento',
            'titulo' => 'Actualización de un documento',
            'mensaje' => "Uno de tus documentos ahora está: {$this->documento->status->etiqueta()}.",
            'url' => null,
            'type' => 'documento',
            'resource_id' => $this->documento->id,
        ];
    }
}
