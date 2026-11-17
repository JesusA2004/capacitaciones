<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Préstamos: permiso propio `prestamos.gestionar` (confirmar la entrega y
 * registrar abonos/ajustes desde el expediente). Antes esas dos acciones se
 * autorizaban con `expedientes.editar`; para no quitarle el acceso a nadie,
 * se otorga a TODO rol que hoy tenga `expedientes.editar` (incluidos los
 * creados a mano desde «Roles y permisos»). Aditiva e idempotente; queda en
 * la bitácora. No revoca nada.
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

        $otorgar = ['super_admin' => ['prestamos.gestionar']];

        foreach ($roles as $rol) {
            $otorgar[(string) $rol] = ['prestamos.gestionar'];
        }

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            [],
            'Préstamos: permiso propio para confirmar la entrega y registrar abonos (antes expedientes.editar).',
        );
    }

    public function down(): void {}
};
