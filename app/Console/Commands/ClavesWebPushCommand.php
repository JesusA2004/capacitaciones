<?php

namespace App\Console\Commands;

use App\Services\WebPush\ClavesVapidService;
use Illuminate\Console\Command;

/**
 * Muestra las llaves VAPID vigentes para fijarlas en el .env
 * (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY). Si no existían, se generan y
 * guardan una sola vez (ver App\Services\WebPush\ClavesVapidService).
 */
class ClavesWebPushCommand extends Command
{
    protected $signature = 'webpush:claves';

    protected $description = 'Muestra (o genera una sola vez) las llaves VAPID de las notificaciones de escritorio';

    public function handle(ClavesVapidService $claves): int
    {
        $vapid = $claves->vapid();

        if ($vapid === null) {
            $this->error('No se pudieron obtener ni generar las llaves VAPID (revisa storage/app/private y el log).');

            return self::FAILURE;
        }

        $this->info('Llaves VAPID vigentes (no las cambies: invalidaría todas las suscripciones):');
        $this->line(sprintf('VAPID_PUBLIC_KEY=%s', $vapid['publicKey']));
        $this->line(sprintf('VAPID_PRIVATE_KEY=%s', $vapid['privateKey']));
        $this->line(sprintf('VAPID_SUBJECT=%s', $vapid['subject']));

        return self::SUCCESS;
    }
}
