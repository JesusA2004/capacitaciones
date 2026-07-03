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
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recursos_multimedia');
    }
};
