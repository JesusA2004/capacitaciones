<?php

namespace App\Console\Commands;

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionUsuario;
use App\Notifications\FechaLimiteProximaNotification;
use Illuminate\Console\Command;

/**
 * Notifica a los colaboradores cuya asignacion vence en los proximos 3 dias
 * y que todavia no la han completado. `recordatorio_enviado_en` evita
 * notificar de nuevo en cada ejecucion diaria del scheduler por la misma
 * fecha limite.
 */
class RecordarFechasLimiteCommand extends Command
{
    protected $signature = 'capacitacion:recordar-fechas-limite';

    protected $description = 'Notifica a los colaboradores con asignaciones por vencer en los próximos 3 días';

    public function handle(): int
    {
        $asignaciones = AsignacionUsuario::query()
            ->whereIn('estado', [EstadoAsignacion::Pendiente->value, EstadoAsignacion::EnProgreso->value])
            ->whereNull('recordatorio_enviado_en')
            ->whereNotNull('fecha_limite')
            ->whereBetween('fecha_limite', [now(), now()->addDays(3)])
            ->with('usuario')
            ->get();

        foreach ($asignaciones as $asignacionUsuario) {
            $asignacionUsuario->usuario->notify(new FechaLimiteProximaNotification($asignacionUsuario));
            $asignacionUsuario->update(['recordatorio_enviado_en' => now()]);
        }

        $this->info("Recordatorios de fecha límite enviados: {$asignaciones->count()}.");

        return self::SUCCESS;
    }
}
