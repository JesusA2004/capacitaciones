<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado LÓGICO de roles («Roles y permisos» → Desactivar): un rol
 * desactivado conserva sus permisos y sus usuarios, pero no otorga nada
 * (App\Models\User ignora sus permisos) ni se ofrece al asignar roles. Se
 * reactiva cuando se quiera. El borrado físico es solo de super_admin
 * (RolPermisoService::eliminarDefinitivamente).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabla = (string) config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($tabla, 'desactivado_en')) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->timestamp('desactivado_en')->nullable();
            $table->foreignId('desactivado_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tabla = (string) config('permission.table_names.roles', 'roles');

        if (! Schema::hasColumn($tabla, 'desactivado_en')) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) {
            $table->dropConstrainedForeignId('desactivado_por');
            $table->dropColumn('desactivado_en');
        });
    }
};
