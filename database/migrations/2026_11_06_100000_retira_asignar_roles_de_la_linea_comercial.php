<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * «Asignar roles» es de administración (super_admin, RH admin y Sistemas): los
 * gerentes, regionales y el administrador de capacitación lo tenían por herencia
 * de usuarios.editar y podían dar cualquier rol, incluido super_admin.
 * people:sincronizar-permisos solo agrega, por eso el retiro va aquí.
 */
return new class extends Migration
{
    private const ROLES = ['gerente', 'gerente_sucursal', 'gerente_regional', 'administrador_capacitacion'];

    public function up(): void
    {
        $this->pivote()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Devuelve el permiso a esos roles, si existen. */
    public function down(): void
    {
        $permiso = DB::table('permissions')->where('name', 'usuarios.asignar_roles')->value('id');

        if ($permiso === null) {
            return;
        }

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $rol) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function pivote(): Builder
    {
        return DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->whereIn('name', self::ROLES)->select('id'))
            ->where('permission_id', DB::table('permissions')->where('name', 'usuarios.asignar_roles')->value('id'));
    }
};
