<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos especiales por persona (App\Services\Permisos\PermisosEspecialesService):
 * sin tocar su rol, a una cuenta se le CONCEDE o se le QUITA un permiso, con
 * motivo, quién y vigencia opcional («hasta», p. ej. una cobertura). Se
 * aplican en un solo punto (App\Models\User::hasPermissionTo y compañía),
 * así que valen en web, API móvil, Policies, menú y avisos por permiso.
 *
 * - permisos_usuario_ajustes: lo vigente (uno por permiso y persona). Quitar
 *   todos los ajustes deja a la persona EXACTAMENTE con lo de su rol.
 * - permisos_usuario_bitacora: quién concedió/quitó/retiró qué y cuándo
 *   (nunca se borra al retirar un ajuste).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permisos_usuario_ajustes')) {
            Schema::create('permisos_usuario_ajustes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('permiso', 150);
                // conceder | revocar (App\Enums\TipoAjustePermiso).
                $table->string('tipo', 20);
                $table->string('motivo', 500)->nullable();
                // Vence sola: después de esta fecha el ajuste deja de contar.
                $table->timestamp('vigente_hasta')->nullable();
                $table->foreignId('otorgado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'permiso'], 'permisos_usuario_ajustes_usuario_permiso_unique');
                $table->index('permiso', 'permisos_usuario_ajustes_permiso_index');
            });
        }

        if (! Schema::hasTable('permisos_usuario_bitacora')) {
            Schema::create('permisos_usuario_bitacora', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('permiso', 150)->nullable();
                // App\Enums\AccionAjustePermiso.
                $table->string('accion', 30);
                $table->string('tipo', 20)->nullable();
                $table->string('motivo', 500)->nullable();
                $table->timestamp('vigente_hasta')->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['user_id', 'created_at'], 'permisos_usuario_bitacora_usuario_fecha_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_usuario_bitacora');
        Schema::dropIfExists('permisos_usuario_ajustes');
    }
};
