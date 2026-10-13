<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * El rol jefe_directo tenía `expedientes.ver_sucursal` como truco para
 * "ver expedientes más allá del mío" (su alcance real ya era su equipo).
 * Con el alcance por módulo (config/permisos_modulos.php) ese permiso
 * significa literalmente "toda la sucursal", así que se cambia por
 * `expedientes.ver_equipo` SOLO en ese rol. Incremental e idempotente; no
 * toca ningún otro rol ni personalización.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tablas = config('permission.table_names');
        $roles = (string) ($tablas['roles'] ?? 'roles');
        $permisos = (string) ($tablas['permissions'] ?? 'permissions');
        $pivote = (string) ($tablas['role_has_permissions'] ?? 'role_has_permissions');

        if (! Schema::hasTable($roles) || ! Schema::hasTable($permisos) || ! Schema::hasTable($pivote)) {
            return;
        }

        $rolId = DB::table($roles)->where('name', 'jefe_directo')->where('guard_name', 'web')->value('id');
        $sucursalId = DB::table($permisos)->where('name', 'expedientes.ver_sucursal')->where('guard_name', 'web')->value('id');

        if ($rolId === null || $sucursalId === null) {
            return;
        }

        $equipoId = DB::table($permisos)->where('name', 'expedientes.ver_equipo')->where('guard_name', 'web')->value('id')
            ?? DB::table($permisos)->insertGetId(['name' => 'expedientes.ver_equipo', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        DB::table($pivote)->where('role_id', $rolId)->where('permission_id', $sucursalId)->delete();
        DB::table($pivote)->insertOrIgnore(['role_id' => $rolId, 'permission_id' => $equipoId]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversa: volver a poner el truco ampliaría el alcance del rol.
    }
};
