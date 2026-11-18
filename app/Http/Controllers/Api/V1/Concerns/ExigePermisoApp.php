<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\TipoSolicitudInterna;
use App\Models\User;
use App\Services\Permisos\PermisosAppMovilService;

/**
 * Permiso por botón de la app móvil (App\Support\CatalogoPermisosAppMovil):
 * 403 con el motivo en español si la persona no lo tiene.
 */
trait ExigePermisoApp
{
    protected function exigirPermisoApp(string $permiso): void
    {
        $usuario = request()->user();

        abort_unless($usuario instanceof User, 401);

        app(PermisosAppMovilService::class)->autorizar($usuario, $permiso);
    }

    /**
     * Basta uno (p. ej. el PDF de la inducción sirve para verla y para
     * seguir la presentación en vivo). Sin ninguno: 403 con el primero.
     *
     * @param  non-empty-list<string>  $permisos
     */
    protected function exigirAlgunPermisoApp(array $permisos): void
    {
        foreach ($permisos as $permiso) {
            if ($this->puedePermisoApp($permiso)) {
                return;
            }
        }

        $this->exigirPermisoApp($permisos[0]);
    }

    protected function exigirPermisoCrearSolicitud(TipoSolicitudInterna $tipo): void
    {
        $usuario = request()->user();

        abort_unless($usuario instanceof User, 401);

        app(PermisosAppMovilService::class)->autorizarCrearSolicitud($usuario, $tipo);
    }

    protected function puedeCrearSolicitudApp(TipoSolicitudInterna $tipo): bool
    {
        $usuario = request()->user();

        return $usuario instanceof User && app(PermisosAppMovilService::class)->puedeCrearSolicitud($usuario, $tipo);
    }

    protected function puedePermisoApp(string $permiso): bool
    {
        $usuario = request()->user();

        return $usuario instanceof User && app(PermisosAppMovilService::class)->puede($usuario, $permiso);
    }
}
