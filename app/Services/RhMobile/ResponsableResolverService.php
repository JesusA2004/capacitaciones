<?php

namespace App\Services\RhMobile;

use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Support\Collection;

/**
 * Resuelve quien debe enterarse (in-app + push) de un evento de un
 * colaborador dado: nunca lo decide el frontend, siempre el backend a
 * partir de permisos + alcance organizacional (empresa/sucursal/jefe). Ver
 * seccion 16 y 18 del encargo movil, y docs/RH_MOBILE_API.md.
 */
class ResponsableResolverService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Usuarios con el permiso dado cuyo alcance organizacional cubre a
     * `$colaborador` (o el propio jefe directo del colaborador, si tiene
     * ese permiso). El propio colaborador nunca aparece en el resultado.
     *
     * @return Collection<int, User>
     */
    public function paraColaborador(User $colaborador, string $permiso): Collection
    {
        return User::query()
            ->permission($permiso)
            ->where('id', '!=', $colaborador->id)
            ->get()
            ->filter(fn (User $candidato) => $this->alcance->puedeVerUsuario($candidato, $colaborador))
            ->values();
    }
}
