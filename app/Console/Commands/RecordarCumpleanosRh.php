<?php

namespace App\Console\Commands;

use App\Services\Cumpleanos\CumpleanosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recordatorio diario a RH/admin con permiso rh.cumpleanos.ver: cumpleanos
 * de hoy y de los proximos 7 dias. No duplica destinatarios ni notifica si
 * no hay nada que avisar (ver
 * App\Services\Cumpleanos\CumpleanosService::notificarRh).
 */
class RecordarCumpleanosRh extends Command
{
    protected $signature = 'cumpleanos:recordar-rh';

    protected $description = 'Envía a RH/admin un recordatorio de los cumpleaños de hoy y de los próximos 7 días';

    public function handle(CumpleanosService $cumpleanos): int
    {
        if (! (bool) config('cumpleanos.enabled')) {
            $this->info('Módulo de cumpleaños deshabilitado (CUMPLEANOS_ENABLED=false). No se hizo nada.');

            return self::SUCCESS;
        }

        try {
            $cumpleanos->notificarRh();
        } catch (\Throwable $e) {
            Log::error('cumpleanos:recordar-rh: fallo general', ['error' => $e->getMessage()]);

            $this->error('Ocurrió un error al enviar el recordatorio a RH. Ver logs.');

            return self::SUCCESS;
        }

        $this->info('Recordatorio de cumpleaños enviado a RH/admin.');

        return self::SUCCESS;
    }
}
