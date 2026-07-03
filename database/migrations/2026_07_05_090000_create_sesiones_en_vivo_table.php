<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_en_vivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leccion_id')->unique()->constrained('lecciones')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('proveedor')->default('manual');
            $table->dateTime('fecha_inicio');
            $table->unsignedInteger('duracion_minutos')->default(60);
            $table->string('enlace_reunion')->nullable();
            $table->string('id_reunion_externa')->nullable();
            $table->json('datos_proveedor')->nullable();
            $table->string('estado')->default('programada');
            $table->foreignId('creado_por')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_en_vivo');
    }
};
