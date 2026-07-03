<?php

namespace App\Console\Commands;

use App\Enums\EstadoEntregaActividad;
use App\Enums\EstadoIntentoCuestionario;
use App\Models\EntregaActividad;
use App\Models\IntentoCuestionario;
use App\Models\User;
use App\Notifications\CalificacionesPendientesNotification;
use Illuminate\Console\Command;

/**
 * Resumen diario para quien tenga el permiso respuestas.calificar de cuantos
 * cuestionarios/actividades siguen pendientes de revision manual. Igual que
 * App\Services\Reportes\MetricasDashboardService::pendientesDeCalificar()
 * (Fase 6), el conteo es global y no se filtra por
 * AlcanceOrganizacionalService: cualquier calificador ve el mismo total,
 * consistente con como ya se muestra en el dashboard. No se envia si no hay
 * nada pendiente, para no generar ruido diario.
 */
class RecordarCalificacionesPendientesCommand extends Command
{
    protected $signature = 'capacitacion:recordar-calificaciones-pendientes';

    protected $description = 'Envía un resumen diario de cuestionarios/actividades pendientes de calificar';

    public function handle(): int
    {
        $cuestionariosPendientes = IntentoCuestionario::query()->where('estado', EstadoIntentoCuestionario::Enviado->value)->count();
        $actividadesPendientes = EntregaActividad::query()->where('estado', EstadoEntregaActividad::Entregada->value)->count();

        if ($cuestionariosPendientes === 0 && $actividadesPendientes === 0) {
            $this->info('No hay calificaciones pendientes.');

            return self::SUCCESS;
        }

        $calificadores = User::permission('respuestas.calificar')->get();

        foreach ($calificadores as $calificador) {
            $calificador->notify(new CalificacionesPendientesNotification($cuestionariosPendientes, $actividadesPendientes));
        }

        $this->info("Calificadores notificados: {$calificadores->count()}.");

        return self::SUCCESS;
    }
}
