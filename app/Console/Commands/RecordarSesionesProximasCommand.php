<?php

namespace App\Console\Commands;

use App\Enums\EstadoSesionEnVivo;
use App\Models\SesionEnVivo;
use App\Notifications\SesionEnVivoProximaNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Notifica a los participantes con asistencia registrada de una sesion en
 * vivo que comienza en la proxima hora. `recordatorio_enviado_en` evita
 * notificar de nuevo en cada ejecucion del scheduler por la misma sesion.
 */
class RecordarSesionesProximasCommand extends Command
{
    protected $signature = 'capacitacion:recordar-sesiones-proximas';

    protected $description = 'Notifica a los participantes de sesiones en vivo que comienzan en la próxima hora';

    public function handle(): int
    {
        $sesiones = SesionEnVivo::query()
            ->where('estado', EstadoSesionEnVivo::Programada->value)
            ->whereNull('recordatorio_enviado_en')
            ->whereBetween('fecha_inicio', [now(), now()->addHour()])
            ->with('asistencias.usuario')
            ->get();

        foreach ($sesiones as $sesion) {
            $usuarios = $sesion->asistencias->pluck('usuario')->filter();

            if ($usuarios->isNotEmpty()) {
                Notification::send($usuarios, new SesionEnVivoProximaNotification($sesion));
            }

            $sesion->update(['recordatorio_enviado_en' => now()]);
        }

        $this->info("Sesiones con recordatorio enviado: {$sesiones->count()}.");

        return self::SUCCESS;
    }
}
