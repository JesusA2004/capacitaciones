<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Las solicitudes internas ya no tienen fase "cerrada": una solicitud
 * aprobada es definitiva. Las que estaban "cerradas" vuelven a "aprobada"
 * (nada se borra: el renglón del historial se conserva como comentario con
 * su texto original, p. ej. "Expediente cerrado.") y se retira el permiso
 * `solicitudes.cerrar`, que ya no tiene acción asociada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('solicitudes_internas')->where('estado', 'cerrada')->update(['estado' => 'aprobada']);
            DB::table('solicitud_interna_historial')->where('accion', 'cerrada')->update(['accion' => 'comentario']);

            $permisoId = DB::table('permissions')->where('name', 'solicitudes.cerrar')->value('id');

            if ($permisoId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permisoId)->delete();
                DB::table('permissions')->where('id', $permisoId)->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sin reversa: el estado "cerrada" dejó de existir en el código.
    }
};
