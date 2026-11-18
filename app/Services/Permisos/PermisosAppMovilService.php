<?php

namespace App\Services\Permisos;

use App\Models\User;
use App\Services\Directorio\DirectorioColaboradoresService;
use App\Support\CatalogoPermisosAppMovil;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos por botón de la app móvil (CatalogoPermisosAppMovil). Los usan
 * los controladores de la API v1 de autoservicio y el bootstrap de la app.
 *
 * Igual que PermisoAccionService, el despliegue nunca deja a nadie sin lo
 * que ya hacía: mientras el permiso todavía no existe en la base (entre el
 * `git pull` y la migración/`people:sincronizar-permisos`), la acción se
 * permite como antes. En cuanto existe, manda lo que diga «Roles y
 * permisos» (rol + permisos directos de la persona, vía Spatie `can()`).
 */
class PermisosAppMovilService
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly DirectorioColaboradoresService $directorio,
    ) {}

    public function puede(User $usuario, string $permiso): bool
    {
        if (! $this->existe($permiso)) {
            return true;
        }

        return $usuario->can($permiso);
    }

    /**
     * 403 con el motivo en español si la persona no tiene el permiso.
     */
    public function autorizar(User $usuario, string $permiso): void
    {
        abort_unless(
            $this->puede($usuario, $permiso),
            403,
            sprintf('No tienes permiso para «%s» en la app. Si lo necesitas, pídeselo a Recursos Humanos.', CatalogoPermisosAppMovil::etiqueta($permiso)),
        );
    }

    /**
     * Mapa permiso => bool con lo que la app debe ofrecer a esta persona
     * (`permisos_app` del bootstrap y de `me`). Incluye los permisos web
     * reutilizados por la app cuyo resultado no sale solo de Spatie.
     *
     * @return array<string, bool>
     */
    public function efectivos(User $usuario): array
    {
        $efectivos = [];

        foreach (CatalogoPermisosAppMovil::permisos() as $permiso) {
            $efectivos[$permiso] = $this->puede($usuario, $permiso);
        }

        // Directorio: permiso web de siempre + «Corporativo entra siempre».
        $efectivos['directorio.ver'] = $this->directorio->puedeEntrar($usuario);

        return $efectivos;
    }

    private function existe(string $permiso): bool
    {
        return $this->registrar->getPermissions(['name' => $permiso, 'guard_name' => 'web'], true)->isNotEmpty();
    }
}
