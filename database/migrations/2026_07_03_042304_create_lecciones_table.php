<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_modulo_id')->constrained('curso_modulos')->cascadeOnDelete();
            $table->string('titulo');
            $table->string('tipo');
            $table->longText('contenido')->nullable();
            $table->string('url')->nullable();
            $table->boolean('obligatoria')->default(true);
            $table->unsignedInteger('orden')->default(0);
            $table->unsignedInteger('duracion_estimada_minutos')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecciones');
    }
};
