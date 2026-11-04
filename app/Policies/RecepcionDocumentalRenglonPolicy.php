<?php

namespace App\Policies;

use App\Enums\EstadoRecepcionDocumento;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\User;
use App\Services\RecepcionDocumental\RecepcionDocumentalService as Servicio;

/**
 * Renglón esperado de Recepción documental: permiso de la acción + alcance
 * por sucursal del módulo (AlcanceOrganizacionalService). Palomear
 * «Recibido» (o regresarlo a pendiente) es `recibir`; observación, «No
 * recibido» y «No aplica» son `observar`. Corte cerrado = solo lectura.
 */
class RecepcionDocumentalRenglonPolicy
{
    public function __construct(private readonly Servicio $servicio) {}

    public function view(User $usuario, RecepcionDocumentalRenglon $renglon): bool
    {
        return $usuario->can(Servicio::PERMISO_VER) && $this->servicio->alcanzaRenglon($usuario, $renglon);
    }

    /** Cambiar el renglón a $estado (lo que pide el endpoint de marcar). */
    public function marcar(User $usuario, RecepcionDocumentalRenglon $renglon, EstadoRecepcionDocumento $estado): bool
    {
        $permiso = $estado === EstadoRecepcionDocumento::Recibido || $estado === EstadoRecepcionDocumento::Pendiente
            ? Servicio::PERMISO_RECIBIR
            : Servicio::PERMISO_OBSERVAR;

        return $usuario->can($permiso) && $this->view($usuario, $renglon);
    }

    public function subirEvidencia(User $usuario, RecepcionDocumentalRenglon $renglon): bool
    {
        return ($usuario->can(Servicio::PERMISO_RECIBIR) || $usuario->can(Servicio::PERMISO_OBSERVAR)) && $this->view($usuario, $renglon);
    }
}
