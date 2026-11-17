<?php

use App\Services\Permisos\AlineacionPermisosRolService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Fechas laborales: permiso propio `expedientes.editar_fechas` (ingreso,
 * inicio de capacitación inicial, inducción, alta IMSS y periodo de prueba
 * específico). Para no quitarle a nadie lo que hoy hace con «Editar
 * expedientes», se otorga a TODO rol que hoy tenga `expedientes.editar`
 * (incluidos los creados a mano). Aditiva e idempotente; no revoca nada.
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

        $otorgar = ['super_admin' => ['expedientes.editar_fechas']];

        foreach ($roles as $rol) {
            $otorgar[(string) $rol] = ['expedientes.editar_fechas'];
        }

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            [],
            'Fechas laborales: permiso propio para editar las fechas del colaborador y su periodo de prueba específico.',
        );
    }

    public function down(): void {}
};
