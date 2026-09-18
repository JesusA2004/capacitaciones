<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separación Usuario/Colaborador (ver docs/ROLES_Y_NAVEGACION.md): esta
 * tabla concentra TODOS los datos de persona/empleo. `users` es solo la
 * cuenta de acceso — `users.colaborador_id` enlaza la cuenta, cuando existe,
 * con su colaborador.
 *
 * Un Colaborador puede existir sin ninguna cuenta de acceso asociada (aún no
 * se le da de alta un usuario, o nunca la necesita).
 *
 * Esta tabla se crea ANTES que `users` (users.colaborador_id la referencia).
 * Por eso `incorporacion_decidida_por` y `avisos_registrado_por_id` — ambas
 * columnas de ACTOR (quién decidió/registró, no la persona del colaborador)
 * — se guardan como id plano sin FK de base de datos: `users` todavía no
 * existe en este punto del orden de migración. La relación Eloquent
 * (belongsTo) funciona igual; solo no hay constraint a nivel de motor.
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
            $table->string('telefono_corporativo')->nullable();
            // Sueldo mensual real del colaborador (no el presupuestado de una
            // vacante): alimenta el recibo de nómina simple y el costo real
            // por colaborador en los KPI de Candidatos/Vacantes.
            $table->decimal('sueldo_mensual', 10, 2)->nullable();
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
            $table->unsignedBigInteger('incorporacion_decidida_por')->nullable();
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
            $table->unsignedBigInteger('avisos_registrado_por_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
