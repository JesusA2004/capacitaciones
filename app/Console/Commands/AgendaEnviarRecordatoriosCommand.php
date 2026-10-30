<?php

namespace App\Console\Commands;

use App\Enums\EstadoAgendaEvento;
use App\Models\AgendaEvento;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Reclutamiento\AgendaEventoService;
use App\Support\FechaMexico;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recordatorios de la agenda: un día antes y el mismo día. Idempotente: la clave
 * de cada aviso incluye la cita y su hora, así que puede correr varias veces y
 * una cita reprogramada o cancelada simplemente deja de salir (solo se leen las
 * programadas). No se recuerda lo que se agendó ese mismo día: ya se avisó.
 */
class AgendaEnviarRecordatoriosCommand extends Command
{
    protected $signature = 'agenda:enviar-recordatorios';

    protected $description = 'Envía a gerente y subgerente los recordatorios de la agenda de reclutamiento.';

    public function handle(AgendaEventoService $agenda, NotificacionEventosService $avisos): int
    {
        $hoy = Carbon::parse(FechaMexico::hoy());
        $enviados = 0;

        foreach (['mismo_dia' => $hoy, 'dia_antes' => $hoy->copy()->addDay()] as $momento => $dia) {
            $eventos = AgendaEvento::query()
                ->where('estado', EstadoAgendaEvento::Programado->value)
                ->whereBetween('fecha_hora', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()])
                ->with(['candidato', 'sucursal:id,nombre'])
                ->get();

            foreach ($eventos as $evento) {
                if ($evento->created_at !== null && $evento->created_at->copy()->setTimezone(FechaMexico::ZONA)->toDateString() === $hoy->toDateString()) {
                    continue;
                }

                try {
                    $titulo = $momento === 'mismo_dia'
                        ? sprintf('Hoy: %s a las %s', mb_strtolower($evento->tipo->etiqueta()), $evento->fecha_hora->format('H:i'))
                        : sprintf('Mañana: %s a las %s', mb_strtolower($evento->tipo->etiqueta()), $evento->fecha_hora->format('H:i'));

                    $enviados += $avisos->crearPendiente(
                        $agenda->destinatarios($evento->sucursal_id),
                        $agenda->aviso($evento, 'agenda_recordatorio', $titulo, sprintf('%s:%d:%s', $momento, $evento->id, $evento->fecha_hora->format('YmdHi'))),
                    )->count();
                } catch (Throwable $e) {
                    Log::warning('agenda:enviar-recordatorios: no se pudo avisar.', ['evento_id' => $evento->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info(sprintf('Recordatorios entregados: %d.', $enviados));

        return self::SUCCESS;
    }
}
