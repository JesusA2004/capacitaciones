<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Administración → Configuración (docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md):
 *
 *  - configuraciones_sistema: valores que el negocio ajusta sin desplegar
 *    (colores institucionales, parámetros de RH). El catálogo de claves
 *    válidas, su tipo, grupo, descripción, valor por defecto y validación
 *    vive versionado en config/configuracion_sistema.php: aquí solo se
 *    guarda lo que se cambió.
 *  - reglas_notificacion: a quién se AVISA de cada evento (destinatarios
 *    dinámicos: jefe directo, gerente de sucursal, RH...). Nunca decide
 *    quién AUTORIZA: eso sigue en Policies + permisos + AprobacionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_sistema', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 120)->unique();
            $table->text('valor')->nullable();
            $table->string('tipo', 20);
            $table->string('grupo', 40)->index();
            $table->string('descripcion', 255)->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reglas_notificacion', function (Blueprint $table): void {
            $table->id();
            $table->string('evento', 80)->unique();
            // Lista de tipos de destinatario (App\Enums\TipoDestinatarioNotificacion).
            $table->json('destinatarios');
            // Para el tipo "usuarios_con_permiso" (siempre acotado al alcance).
            $table->string('permiso', 120)->nullable();
            // Para el tipo "usuario_especifico".
            $table->json('usuario_ids')->nullable();
            // Si nadie resulta de la regla: a quién se avisa (auditable).
            $table->json('fallback')->nullable();
            $table->boolean('activa')->default(true);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_notificacion');
        Schema::dropIfExists('configuraciones_sistema');
    }
};
