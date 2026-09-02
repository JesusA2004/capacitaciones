<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidatos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_objetivo_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('vacante_id')->nullable()->constrained('vacantes')->nullOnDelete();

            $table->string('nombre');
            $table->string('apellidos')->nullable();
            $table->string('telefono')->nullable();
            $table->string('correo')->nullable();
            $table->string('fuente')->nullable();

            // CV: solo metadatos en BD, el archivo vive en el disco "nas" (Synology).
            $table->string('cv_disk')->nullable();
            $table->string('cv_path')->nullable();
            $table->string('cv_original_name')->nullable();
            $table->string('cv_mime')->nullable();
            $table->unsignedBigInteger('cv_size')->nullable();

            $table->text('observaciones')->nullable();
            $table->text('documentos_solicitados')->nullable();

            $table->foreignId('responsable_rh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gerente_involucrado_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('estado', 30)->default('nuevo');

            $table->dateTime('fecha_entrevista')->nullable();
            $table->text('resultado_entrevista')->nullable();

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidatos');
    }
};
