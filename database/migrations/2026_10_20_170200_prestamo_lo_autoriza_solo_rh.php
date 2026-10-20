<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El préstamo lo AUTORIZA SOLO RH (2026-10-20). Gerente, Regional y
 * Dirección Comercial dan su visto bueno en la cadena jerárquica
 * (AprobacionJerarquicaService); el rol `direccion` conserva la consulta
 * (prestamos.ver) pero deja de autorizar. Quitar la línea del seeder no
 * basta en bases existentes (el sincronizador nunca quita permisos), por
 * eso se revoca aquí SOLO ese permiso de SOLO ese rol. Idempotente; si el
 * rol no existe no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles'))) {
            return;
        }

        $rol = Role::query()->where('name', 'direccion')->where('guard_name', 'web')->first();

        if ($rol === null || ! $rol->permissions()->where('name', 'prestamos.autorizar')->exists()) {
            return;
        }

        $rol->revokePermissionTo('prestamos.autorizar');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversa: volver a darlo ampliaría quién autoriza préstamos.
    }
};
