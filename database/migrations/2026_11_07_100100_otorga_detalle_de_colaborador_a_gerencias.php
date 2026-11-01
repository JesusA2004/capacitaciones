<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gerencias: abren en la app la ficha de quien ya ven en su expediente web. Sin
 * esto, sus avisos de alta y de contratos listos terminaban en 403.
 */
return new class extends Migration
{
    private const ROLES = ['gerente', 'gerente_sucursal', 'gerente_regional'];

    public function up(): void
    {
        $permiso = DB::table('permissions')->where('name', 'rh.colaboradores.detalle')->value('id');

        if ($permiso === null) {
            return;
        }

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $rol) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->whereIn('name', self::ROLES)->select('id'))
            ->where('permission_id', DB::table('permissions')->where('name', 'rh.colaboradores.detalle')->value('id'))
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
