<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->text('objetivo')->nullable();
            $table->string('imagen_portada_path')->nullable();
            $table->unsignedInteger('duracion_estimada_minutos')->nullable();
            $table->string('estado')->default('borrador');
            $table->dateTime('disponible_desde')->nullable();
            $table->dateTime('disponible_hasta')->nullable();
            $table->unsignedTinyInteger('calificacion_minima')->nullable();
            $table->unsignedInteger('intentos_maximos')->nullable();
            $table->boolean('requiere_orden')->default(true);
            $table->boolean('genera_constancia')->default(false);
            $table->boolean('alcance_global')->default(true);
            $table->json('etiquetas')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publicado_en')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
