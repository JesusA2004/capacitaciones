<?php

namespace App\Policies;

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

class CandidatoPolicy
{
    /**
     * Transiciones de alto impacto que requieren un permiso de decision
     * (aprobar/rechazar) en vez del permiso general de edicion.
     *
     * @var array<int, string>
     */
    private const ESTADOS_APROBACION = [
        EstadoCandidato::AprobadoGerencia->value,
        EstadoCandidato::AprobadoRh->value,
        EstadoCandidato::Contratado->value,
    ];

    /**
     * @var array<int, string>
     */
    private const ESTADOS_RECHAZO = [
        EstadoCandidato::Rechazado->value,
        EstadoCandidato::Descartado->value,
    ];

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('candidatos.ver');
    }

    public function view(User $usuario, Candidato $candidato): bool
    {
        return $usuario->can('candidatos.ver') && $this->visiblePara($usuario, $candidato);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('candidatos.crear');
    }

    public function update(User $usuario, Candidato $candidato): bool
    {
        return $usuario->can('candidatos.editar') && $this->visiblePara($usuario, $candidato);
    }

    public function cambiarEstado(User $usuario, Candidato $candidato, EstadoCandidato $nuevoEstado): bool
    {
        if (! $this->visiblePara($usuario, $candidato)) {
            return false;
        }

        if (in_array($nuevoEstado->value, self::ESTADOS_APROBACION, true)) {
            return $usuario->can('candidatos.aprobar');
        }

        if (in_array($nuevoEstado->value, self::ESTADOS_RECHAZO, true)) {
            return $usuario->can('candidatos.rechazar');
        }

        return $usuario->can('candidatos.editar');
    }

    public function delete(User $usuario, Candidato $candidato): bool
    {
        return $usuario->can('candidatos.eliminar') && $this->visiblePara($usuario, $candidato);
    }

    private function visiblePara(User $usuario, Candidato $candidato): bool
    {
        if ($usuario->can('candidatos.ver_todos')) {
            return true;
        }

        if (! $usuario->can('candidatos.ver_sucursal')) {
            return false;
        }

        return $candidato->sucursal_id === null
            || $this->alcance->sucursalesVisiblesIds($usuario)->contains($candidato->sucursal_id);
    }
}
