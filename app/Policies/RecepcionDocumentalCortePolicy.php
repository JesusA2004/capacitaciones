<?php

namespace App\Policies;

use App\Models\RecepcionDocumentalCorte;
use App\Models\User;
use App\Services\RecepcionDocumental\RecepcionDocumentalService as Servicio;

/**
 * Recepción documental RH: permiso por acción (config/permisos_modulos.php
 * → recepcion_documental). El alcance por sucursal se aplica a los
 * renglones (RecepcionDocumentalService::visibles); un colaborador común no
 * tiene ninguno de estos permisos. super_admin pasa por el Gate::before
 * global (son permisos del catálogo operativo), nunca por el nombre del rol.
 */
class RecepcionDocumentalCortePolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can(Servicio::PERMISO_VER);
    }

    public function view(User $usuario, RecepcionDocumentalCorte $corte): bool
    {
        return $usuario->can(Servicio::PERMISO_VER);
    }

    /** Crear / recalcular lo esperado. Un corte cerrado nunca se recalcula. */
    public function generar(User $usuario, ?RecepcionDocumentalCorte $corte = null): bool
    {
        return $usuario->can(Servicio::PERMISO_VER)
            && $usuario->can(Servicio::PERMISO_GENERAR)
            && ($corte === null || ! $corte->estaCerrado());
    }

    public function cerrar(User $usuario, RecepcionDocumentalCorte $corte): bool
    {
        return $usuario->can(Servicio::PERMISO_VER)
            && $usuario->can(Servicio::PERMISO_CERRAR)
            && ! $corte->estaCerrado()
            && $corte->materializado_en !== null;
    }

    public function reabrir(User $usuario, RecepcionDocumentalCorte $corte): bool
    {
        return $usuario->can(Servicio::PERMISO_VER)
            && $usuario->can(Servicio::PERMISO_REABRIR)
            && $corte->estaCerrado();
    }

    public function descargarPdf(User $usuario, RecepcionDocumentalCorte $corte): bool
    {
        return $usuario->can(Servicio::PERMISO_VER) && $usuario->can(Servicio::PERMISO_DESCARGAR_PDF);
    }
}
