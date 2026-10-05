<?php

namespace App\Policies;

use App\Models\CambioFotoPerfil;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Quién revisa un cambio de foto de perfil: permiso expedientes.revisar
 * y alcance organizacional sobre esa persona. Nadie aprueba su propia
 * foto. La propuesta la ven los revisores y la propia persona.
 */
class CambioFotoPerfilPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('expedientes.revisar');
    }

    public function revisar(User $usuario, CambioFotoPerfil $cambio): bool
    {
        return $usuario->can('expedientes.revisar')
            && $usuario->colaborador_id !== $cambio->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $cambio->colaborador);
    }

    public function verPropuesta(User $usuario, CambioFotoPerfil $cambio): bool
    {
        return $usuario->colaborador_id === $cambio->colaborador_id || $this->revisar($usuario, $cambio);
    }
}
