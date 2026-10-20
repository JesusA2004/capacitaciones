<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Las importaciones y cargas masivas son SOLO de super_admin (decisión del
 * negocio, 2026-10-20): carga inicial maestra, migración de expedientes,
 * headcount, salarios y vacaciones, contactos de emergencia, Excel de
 * rotación, recibos de nómina por archivo y descriptivos de puesto. RH
 * (rh_admin, rh_auxiliar) conserva las pantallas de CONSULTA.
 *
 * Quitar las líneas del seeder no basta en bases existentes (el
 * sincronizador nunca quita permisos), por eso se revocan aquí SOLO esos
 * permisos de cualquier rol distinto de super_admin, y de las asignaciones
 * directas a usuarios que no son super_admin. No toca ningún otro permiso.
 * Crea los dos permisos nuevos de importación y se los da a super_admin.
 * Idempotente.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS_IMPORTACION = [
        'expedientes.migrar',
        'headcount.importar',
        'colaboradores.importar_salarios_vacaciones',
        'rotacion.cargar',
        'nomina.recibos.importar',
        'contactos_emergencia.importar',
        'perfiles_puesto.importar',
    ];

    /** @var list<string> */
    private const PERMISOS_NUEVOS = ['contactos_emergencia.importar', 'perfiles_puesto.importar'];

    public function up(): void
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return;
        }

        foreach (self::PERMISOS_NUEVOS as $permiso) {
            Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();
        $superAdmin?->givePermissionTo(self::PERMISOS_IMPORTACION);

        $roles = Role::query()->where('guard_name', 'web')->where('name', '!=', 'super_admin')->get();

        foreach ($roles as $rol) {
            $asignados = $rol->permissions()->whereIn('name', self::PERMISOS_IMPORTACION)->pluck('name')->all();

            foreach ($asignados as $permiso) {
                $rol->revokePermissionTo($permiso);
            }
        }

        $usuarios = User::query()
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', self::PERMISOS_IMPORTACION))
            ->get();

        foreach ($usuarios as $usuario) {
            if ($usuario->hasRole('super_admin')) {
                continue;
            }

            foreach ($usuario->getDirectPermissions()->whereIn('name', self::PERMISOS_IMPORTACION) as $permiso) {
                $usuario->revokePermissionTo($permiso);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversa: volver a darlos ampliaría quién importa datos masivos.
    }
};
