<?php

namespace App\Policies;

use App\Models\RecepcionDocumentalCatalogo;
use App\Models\User;
use App\Services\RecepcionDocumental\RecepcionDocumentalService as Servicio;

/**
 * Catálogo configurable de Recepción documental: verlo basta con el acceso
 * al módulo; cambiarlo (encender/apagar, documentos, orden, alcance) exige
 * `recepcion_documental.configurar` (super_admin por el Gate::before global
 * y RH Admin por el seeder).
 */
class RecepcionDocumentalCatalogoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can(Servicio::PERMISO_VER);
    }

    public function configurar(User $usuario): bool
    {
        return $usuario->can(Servicio::PERMISO_VER) && $usuario->can(Servicio::PERMISO_CONFIGURAR);
    }

    public function update(User $usuario, RecepcionDocumentalCatalogo $entrada): bool
    {
        return $this->configurar($usuario);
    }
}
