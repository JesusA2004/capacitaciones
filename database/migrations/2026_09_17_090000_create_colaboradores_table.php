<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Primer paso de la separación Usuario/Colaborador (ver docblock de
 * 2026_09_16_120000_add_expediente_storage_path_a_users.php y
 * docs/ROLES_Y_NAVEGACION.md): esta tabla concentra los datos de
 * persona/empleo que hoy viven en `users` mezclados con las credenciales de
 * acceso. `users` deja de ser dueño de estos datos — `users.colaborador_id`
 * (ver migración add_colaborador_id_a_users) enlaza la cuenta de acceso,
 * cuando existe, con su colaborador.
 *
 * Un Colaborador puede existir sin ninguna cuenta de acceso asociada (aún no
 * se le da de alta un usuario, o nunca la necesita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('apellidos')->nullable();
            $table->string('genero', 20)->nullable();
            $table->string('numero_empleado')->nullable()->unique();
            $table->string('telefono')->nullable();
            $table->string('foto_path')->nullable();
            $table->string('expediente_storage_path')->nullable();

            $table->foreignId('sucursal_principal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('jefe_id')->nullable()->constrained('colaboradores')->nullOnDelete();

            $table->date('fecha_ingreso')->nullable();
            $table->string('estatus')->default('activo');
            $table->string('estatus_imss', 20)->default('pendiente_imss');
            $table->date('fecha_alta_imss')->nullable();
            $table->date('periodo_prueba_inicio')->nullable();
            $table->date('periodo_prueba_fin')->nullable();

            $table->string('incorporacion_decision', 20)->nullable();
            $table->foreignId('incorporacion_decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('incorporacion_decidida_en')->nullable();
            $table->text('incorporacion_motivo_rechazo')->nullable();

            $table->date('fecha_nacimiento')->nullable();
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('correo_personal')->nullable();
            $table->string('contacto_emergencia_nombre')->nullable();
            $table->string('contacto_emergencia_telefono')->nullable();

            $table->boolean('aviso_privacidad_aceptado')->default(false);
            $table->timestamp('aviso_privacidad_aceptado_en')->nullable();
            $table->boolean('consentimiento_datos_aceptado')->default(false);
            $table->timestamp('consentimiento_datos_aceptado_en')->nullable();
            $table->foreignId('avisos_registrado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
