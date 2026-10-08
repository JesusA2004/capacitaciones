<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use App\Services\Tareas\NotificadorRhService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ALTA y BAJA de colaborador → RH + Sistemas (eventos colaborador_alta /
 * colaborador_baja de Configuración → Notificaciones). Los destinatarios se
 * resuelven por rol/puesto/departamento (WorkflowRoutingService), nunca por
 * un usuario fijo. Un fallo al avisar nunca deshace el alta ni la baja.
 */
class AvisoAltaBajaService
{
    public function __construct(private readonly NotificadorRhService $notificador) {}

    public function alta(Colaborador $colaborador): void
    {
        $this->avisar('colaborador_alta', $colaborador, sprintf('Alta: %s', $colaborador->nombreCompleto()), sprintf(
            '%s quedó activo%s. Prepara su cuenta, accesos y equipo.',
            $colaborador->nombreCompleto(),
            $this->detalle($colaborador),
        ));
    }

    public function baja(Colaborador $colaborador, ?string $motivo = null): void
    {
        $this->avisar('colaborador_baja', $colaborador, sprintf('Baja: %s', $colaborador->nombreCompleto()), sprintf(
            'Se dio de baja a %s%s. Retira accesos, cuentas y equipo.%s',
            $colaborador->nombreCompleto(),
            $this->detalle($colaborador),
            $motivo !== null && trim($motivo) !== '' ? ' Motivo: '.mb_substr(trim($motivo), 0, 120) : '',
        ));
    }

    private function avisar(string $evento, Colaborador $colaborador, string $titulo, string $mensaje): void
    {
        try {
            $this->notificador->notificarEvento($evento, $colaborador, [], $titulo, $mensaje, $colaborador, 'ver_expediente', 'media');
        } catch (Throwable $e) {
            Log::warning('AvisoAltaBajaService: no se pudo avisar a RH/Sistemas.', ['evento' => $evento, 'colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }
    }

    private function detalle(Colaborador $colaborador): string
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
        $partes = array_filter([$colaborador->puesto?->nombre, $colaborador->sucursalPrincipal?->nombre]);

        return $partes !== [] ? sprintf(' (%s)', implode(', ', $partes)) : '';
    }
}
