<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Etapa 1 §7: el rol Sistemas deja de consultar documentos con sueldos o
 * importes (contratos, recibos, finiquitos). people:sincronizar-permisos solo
 * agrega, por eso el retiro va aquí. Una cuenta con permiso directo lo conserva.
 */
return new class extends Migration
{
    private const PERMISOS = ['contratos.ver_documento', 'contratos.descargar', 'nomina.recibos.ver', 'finiquitos.ver', 'finiquitos.descargar'];

    public function up(): void
    {
        $this->pivote()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Devuelve al rol los permisos retirados, si el rol y los permisos existen. */
    public function down(): void
    {
        $rol = DB::table('roles')->where('name', 'sistemas')->value('id');

        if ($rol === null) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('name', self::PERMISOS)->pluck('id') as $permiso) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $rol, 'permission_id' => $permiso]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function pivote(): Builder
    {
        return DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->where('name', 'sistemas')->select('id'))
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::PERMISOS)->select('id'));
    }
};
