<?php

namespace App\Notifications\Concerns;

/**
 * Manda cada canal de una notificación encolada a la cola dedicada de
 * avisos (config('queue.cola_notificaciones'), worker
 * deploy/supervisor/people-notifications.conf), nunca detrás de un PDF o
 * una extracción en la cola general.
 */
trait EnColaDeAvisos
{
    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        $cola = (string) config('queue.cola_notificaciones', 'notifications');

        return ['database' => $cola, 'broadcast' => $cola, 'mail' => $cola];
    }
}
