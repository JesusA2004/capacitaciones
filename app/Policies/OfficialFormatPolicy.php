<?php

namespace App\Policies;

use App\Models\OfficialFormat;
use App\Models\User;

/**
 * Plantillas oficiales (docs/FORMATOS_OFICIALES.md → "Permisos"). Cada
 * capacidad es un permiso propio: ver el catálogo y generar no implica
 * poder subir, remapear, publicar o archivar plantillas.
 */
class OfficialFormatPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('formatos_oficiales.ver');
    }

    public function view(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('formatos_oficiales.crear');
    }

    /**
     * Mapear campos de un borrador, ver el editor y su vista previa.
     */
    public function configurar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.configurar');
    }

    /**
     * Crear versiones nuevas, publicarlas o descartar borradores.
     */
    public function versionar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.versionar');
    }

    public function archivar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.archivar');
    }

    public function generar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.generar');
    }

    public function descargar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.descargar');
    }
}
