<?php

namespace App\Notifications\Mobile;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\Mobile\Concerns\BroadcastsNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RhDocumentoPendienteNotification extends Notification implements ShouldQueue
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
            'tipo' => 'rh_documento',
            'titulo' => 'Documento por revisar',
            'mensaje' => 'Un colaborador subió un documento a su expediente.',
            'url' => null,
            'type' => 'rh_documento',
            'resource_id' => $this->documento->id,
        ];
    }
}
