<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recursos_multimedia', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            // Auditoría de cumplimiento: separa un archivo de la biblioteca
            // administrativa (biblioteca) de una evidencia de entrega de
            // actividad/cuestionario (actividad/cuestionario), sin duplicar
            // la tabla ni el servicio de almacenamiento.
            $table->string('origen', 20)->default('biblioteca');
            $table->string('visibilidad', 20)->default('publica');
            $table->string('nombre_original');
            $table->string('nombre_interno')->unique();
            $table->string('disco')->default('nas');
            $table->string('ruta_original');
            $table->string('ruta_hls_manifiesto')->nullable();
            $table->string('ruta_miniatura')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();
            $table->string('resolucion_original')->nullable();
            $table->string('hash_sha256')->nullable();
            $table->string('estado')->default('pendiente');
            $table->text('error_procesamiento')->nullable();
            $table->json('metadatos')->nullable();
            $table->foreignId('subido_por')->constrained('users')->cascadeOnDelete();
            $table->foreignId('propietario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('acceso_restringido')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['origen', 'visibilidad'], 'recursos_multimedia_origen_visibilidad_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recursos_multimedia');
    }
};
