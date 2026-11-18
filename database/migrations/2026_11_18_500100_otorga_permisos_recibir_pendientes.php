<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use App\Support\CatalogoPermisosPendientes;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pendientes por rol (docs/PENDIENTES.md): un permiso
 * `pendientes.recibir_<tipo>` por tipo de pendiente. Hoy a cada rol le
 * llegan todos los que su permiso de acción le asigna, así que se crean y
 * se otorgan a TODOS los roles existentes (también los personalizados):
 * nadie deja de recibir nada al desplegar. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permisos = CatalogoPermisosPendientes::permisos();

        foreach ($permisos as $permiso) {
            Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $otorgar = [];

        foreach (Role::query()->where('guard_name', 'web')->pluck('name') as $rol) {
            $otorgar[(string) $rol] = $permisos;
        }

        if ($otorgar === []) {
            return;
        }

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            [],
            'Pendientes por rol: un permiso «Recibir» por tipo de pendiente, de inicio para todos los roles.',
        );
    }

    public function down(): void {}
};
