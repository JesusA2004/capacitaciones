<?php

namespace App\Policies;

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Visibilidad y edición de candidatos. Los AVANCES del pipeline no se
 * autorizan aquí: los valida App\Services\Reclutamiento\CandidatoWorkflowService
 * (permiso del paso + alcance + organigrama para preautorizar + RH para la
 * autorización final), igual para web y API.
 */
class CandidatoPolicy
{
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

    /**
     * Desde el tablero solo se puede CERRAR el proceso (estados de salida con
     * motivo); avanzar es una acción del workflow.
     */
    public function cambiarEstado(User $usuario, Candidato $candidato, EstadoCandidato $nuevoEstado): bool
    {
        return $this->visiblePara($usuario, $candidato)
            && $nuevoEstado->esSalida()
            && ($usuario->can('candidatos.rechazar') || $usuario->can('candidatos.editar') || $usuario->can('candidatos.evaluar'));
    }

    public function delete(User $usuario, Candidato $candidato): bool
    {
        // Un candidato que ya entró a contratación es el origen de una
        // persona en Etapa 2: su historial no se borra.
        return $usuario->can('candidatos.eliminar')
            && $this->visiblePara($usuario, $candidato)
            && $candidato->colaborador_id === null
            && $candidato->estado->orden() < EstadoCandidato::EnContratacion->orden();
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
            || $candidato->gerente_involucrado_id === $usuario->id
            || $this->alcance->sucursalesVisiblesIds($usuario)->contains($candidato->sucursal_id);
    }
}
