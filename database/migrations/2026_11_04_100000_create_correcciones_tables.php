<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistema universal de correcciones (docs/CORRECCIONES.md): `correcciones` y
 * `correccion_campos` son inmutables (los modelos rechazan editar o borrar).
 * Solo se agregan columnas a tablas existentes: las filas viejas de
 * `historial_salarios` quedan con `tipo` NULL, no se inventa una clasificación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correcciones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('clave_idempotencia');
            $table->string('dominio', 40);
            $table->string('sujeto_type', 120);
            $table->unsignedBigInteger('sujeto_id');
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('modo', 30);
            $table->string('tipo', 30)->nullable();
            $table->char('huella_anterior', 64);
            $table->char('huella_nueva', 64);
            $table->text('motivo');
            $table->date('vigente_desde')->nullable();
            $table->json('afectados')->nullable();
            $table->string('resultado_type', 120)->nullable();
            $table->unsignedBigInteger('resultado_id')->nullable();
            $table->string('origen', 20);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique('clave_idempotencia', 'correcciones_clave_idempotencia_uq');
            $table->unique(['sujeto_type', 'sujeto_id', 'version'], 'correcciones_sujeto_version_uq');
            $table->index(['dominio', 'sujeto_id'], 'correcciones_dominio_sujeto_idx');
            $table->index(['colaborador_id', 'created_at'], 'correcciones_colaborador_fecha_idx');
        });

        Schema::create('correccion_campos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('correccion_id')->constrained('correcciones')->cascadeOnDelete();
            $table->string('campo', 80);
            $table->string('etiqueta', 120);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->boolean('confidencial')->default(false);

            $table->index('correccion_id', 'correccion_campos_correccion_idx');
        });

        Schema::table('historial_salarios', function (Blueprint $table): void {
            $table->string('tipo', 30)->nullable()->after('colaborador_id');
            $table->decimal('sueldo_mensual_anterior', 10, 2)->nullable()->after('tipo');
            $table->decimal('sueldo_mensual_nuevo', 10, 2)->nullable()->after('sueldo_mensual_anterior');
        });

        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->foreignId('rectifica_recibo_id')->nullable()->constrained('recibos_nomina')->nullOnDelete();
            $table->foreignId('rectificado_por_id')->nullable()->constrained('recibos_nomina')->nullOnDelete();
            $table->json('datos_emision')->nullable();
        });

        Schema::table('nomina_rectificaciones', function (Blueprint $table): void {
            $table->string('origen', 20)->default('incidencia');
            $table->json('conceptos_propuestos')->nullable();
            $table->foreignId('recibo_resultante_id')->nullable()->constrained('recibos_nomina')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nomina_rectificaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recibo_resultante_id');
            $table->dropColumn(['origen', 'conceptos_propuestos']);
        });

        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rectifica_recibo_id');
            $table->dropConstrainedForeignId('rectificado_por_id');
            $table->dropColumn('datos_emision');
        });

        Schema::table('historial_salarios', function (Blueprint $table): void {
            $table->dropColumn(['tipo', 'sueldo_mensual_anterior', 'sueldo_mensual_nuevo']);
        });

        Schema::dropIfExists('correccion_campos');
        Schema::dropIfExists('correcciones');
    }
};
