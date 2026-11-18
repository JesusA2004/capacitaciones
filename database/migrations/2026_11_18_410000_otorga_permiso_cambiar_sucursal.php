<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * «Cambio de sucursal» (mismo puesto): permiso propio
 * `expedientes.cambiar_sucursal`. Para no quitarle a nadie lo que hoy hace
 * (cambiar la sucursal desde «Datos laborales» o trasladar personas), se
 * otorga a TODO rol que hoy tenga `expedientes.editar` (incluidos los creados
 * a mano). Aditiva e idempotente; no revoca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'expedientes.editar'))
            ->pluck('name')
            ->all();

        $otorgar = ['super_admin' => ['expedientes.cambiar_sucursal']];

        foreach ($roles as $rol) {
            $otorgar[(string) $rol] = ['expedientes.cambiar_sucursal'];
        }

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            [],
            'Cambio de sucursal: permiso propio para mover a una persona de sucursal sin cambiar su puesto.',
        );
    }

    public function down(): void {}
};
