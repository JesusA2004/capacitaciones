<?php

use App\Models\User;
use App\Services\Permisos\AlineacionPermisosRolService;
use App\Support\CatalogoPermisosAppMovil;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos por botón de la app móvil (App\Support\CatalogoPermisosAppMovil).
 * Hasta hoy esas acciones de autoservicio bastaba con la sesión para
 * hacerlas, así que se crean y se otorgan a TODOS los roles existentes
 * (también los personalizados) y a las cuentas sin ningún rol: nadie pierde
 * ni gana nada al desplegar; «Roles y permisos» quita lo que el negocio
 * decida. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permisos = CatalogoPermisosAppMovil::permisos();

        foreach ($permisos as $permiso) {
            Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $otorgar = [];

        foreach (Role::query()->where('guard_name', 'web')->pluck('name') as $rol) {
            $otorgar[(string) $rol] = $permisos;
        }

        if ($otorgar !== []) {
            app(AlineacionPermisosRolService::class)->ajustar(
                $otorgar,
                [],
                'App móvil por botón: un permiso por acción de autoservicio, de inicio para todos los roles.',
            );
        }

        // Cuentas sin rol que hoy usan la app con su colaborador.
        User::query()
            ->whereNotNull('colaborador_id')
            ->whereDoesntHave('roles')
            ->each(fn (User $usuario) => $usuario->givePermissionTo($permisos));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
