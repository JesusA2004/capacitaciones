<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('apellidos')->nullable();
            $table->string('genero', 20)->nullable();
            $table->string('numero_empleado')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('telefono')->nullable();
            $table->string('foto_path')->nullable();

            $table->foreignId('sucursal_principal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('jefe_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('fecha_ingreso')->nullable();
            $table->string('estatus')->default('activo');
            $table->string('estatus_imss', 20)->default('pendiente_imss');
            $table->date('fecha_alta_imss')->nullable();
            $table->date('periodo_prueba_inicio')->nullable();
            $table->date('periodo_prueba_fin')->nullable();

            // Decision final de RH sobre la incorporacion de un colaborador
            // (distinta de la revision documento por documento, que vive en
            // employee_documents.status). Mientras no haya decision, el
            // estado de incorporacion se calcula en vivo a partir de los
            // documentos requeridos (ver App\Services\Incorporacion\IncorporacionService).
            $table->string('incorporacion_decision', 20)->nullable();
            $table->foreignId('incorporacion_decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('incorporacion_decidida_en')->nullable();
            $table->text('incorporacion_motivo_rechazo')->nullable();

            $table->timestamp('ultimo_acceso')->nullable();
            $table->string('zona_horaria')->default('America/Mexico_City');
            $table->json('preferencias_notificaciones')->nullable();
            // Personalización visual (tema de color, color de avatar,
            // animaciones): ver Settings\PersonalizacionController. Nunca
            // datos de negocio, solo preferencias de interfaz del usuario.
            $table->json('preferencias_ui')->nullable();

            $table->date('fecha_nacimiento')->nullable();
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('correo_personal')->nullable();
            $table->string('contacto_emergencia_nombre')->nullable();
            $table->string('contacto_emergencia_telefono')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
