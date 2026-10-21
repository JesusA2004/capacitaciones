<?php

namespace App\Services\Nomina;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Préstamo disponible»: cuando un colaborador activo cumple la antigüedad
 * mínima para pedir un préstamo (regla del motor:
 * AprobacionOrganizacionalService::elegibilidadPrestamo(), 6 meses
 * completos desde fecha_ingreso con la fecha del SERVIDOR), recibe UN aviso
 * (campana + push). Solo a él: nunca a RH ni a la plantilla.
 *
 * Idempotente: la ocurrencia del aviso es fija por persona
 * («prestamo_disponible»), así que correrlo varias veces el mismo día o
 * cualquier otro día nunca repite el aviso (notificacion_entregas). Solo
 * revisa a quien cumplió la antigüedad en los últimos DIAS_VENTANA días,
 * para no avisar de golpe a toda la plantilla antigua al desplegar.
 */
class PrestamoDisponibleService
{
    public const DIAS_VENTANA = 7;

    public function __construct(
        private readonly AprobacionOrganizacionalService $motor,
        private readonly NotificacionEventosService $eventos,
    ) {}

    /**
     * @return array{revisados: int, avisados: int}
     */
    public function avisar(?CarbonInterface $hoy = null): array
    {
        $hoy = Carbon::parse(($hoy ?? now())->toDateString())->startOfDay();
        $meses = max(0, (int) config('solicitudes.prestamo_meses_minimos', 6));
        $hasta = $hoy->copy()->subMonthsNoOverflow($meses);
        $desde = $hasta->copy()->subDays(self::DIAS_VENTANA);
        $resultado = ['revisados' => 0, 'avisados' => 0];

        Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('fecha_ingreso')
            ->whereDate('fecha_ingreso', '>=', $desde->toDateString())
            ->whereDate('fecha_ingreso', '<=', $hasta->toDateString())
            ->with('user')
            ->chunkById(200, function ($colaboradores) use ($hoy, &$resultado): void {
                foreach ($colaboradores as $colaborador) {
                    $resultado['revisados']++;
                    $usuario = $colaborador->user;

                    if (! $usuario instanceof User || ! $usuario->puedeAccederPortal()) {
                        continue;
                    }

                    try {
                        $elegibilidad = $this->motor->elegibilidadPrestamo($colaborador, $hoy);

                        if (! $elegibilidad['cumple']) {
                            continue;
                        }

                        $aviso = new Aviso(
                            'prestamo_disponible',
                            'prestamo_disponible',
                            'Ya puedes solicitar un préstamo',
                            sprintf('Cumpliste %d meses de antigüedad: desde el %s puedes solicitar un préstamo interno en Mis solicitudes.', (int) $elegibilidad['meses_minimos'], $elegibilidad['disponible_desde_texto'] ?? $hoy->format('d/m/Y')),
                            $colaborador,
                            'solicitar_prestamo',
                            'media',
                            [],
                            'prestamo_disponible',
                        );

                        if ($this->eventos->avisarResultado($usuario, $aviso)) {
                            $resultado['avisados']++;
                        }
                    } catch (Throwable $e) {
                        Log::warning('PrestamoDisponibleService: no se pudo avisar.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $resultado;
    }
}
