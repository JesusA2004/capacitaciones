<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('altas_digitales', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('candidato_id')->nullable()->constrained('candidatos')->nullOnDelete();
            $table->foreignId('vacante_id')->nullable()->constrained('vacantes')->nullOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();

            $table->string('token', 64)->unique();
            $table->dateTime('token_expira_en')->nullable();
            $table->string('estado', 30)->default('creada');

            // Paso 1: datos personales (los captura el candidato en la liga publica).
            $table->string('nombre')->nullable();
            $table->string('apellidos')->nullable();
            $table->string('telefono')->nullable();
            $table->string('correo')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('contacto_emergencia_nombre')->nullable();
            $table->string('contacto_emergencia_telefono')->nullable();

            // Paso 2: datos laborales precargados por RH (solo lectura para el candidato).
            $table->date('fecha_ingreso_propuesta')->nullable();

            // Fotografia (metadatos; archivo en disco "nas").
            $table->string('foto_disk')->nullable();
            $table->string('foto_path')->nullable();
            $table->string('foto_original_name')->nullable();

            // Firma simple (metadatos; archivo en disco "nas").
            $table->string('firma_disk')->nullable();
            $table->string('firma_path')->nullable();

            // Paso 4: aviso de privacidad y consentimiento.
            $table->boolean('aviso_privacidad_aceptado')->default(false);
            $table->dateTime('aviso_privacidad_aceptado_en')->nullable();
            $table->boolean('consentimiento_datos_aceptado')->default(false);
            $table->dateTime('consentimiento_datos_aceptado_en')->nullable();

            $table->dateTime('enviada_en')->nullable();

            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisado_en')->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('aprobado_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->text('comentarios')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('altas_digitales');
    }
};
