<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intentos_cuestionario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuestionario_id')->constrained('cuestionarios')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('numero_intento');
            $table->string('estado')->default('en_progreso');
            $table->timestamp('iniciado_en');
            $table->timestamp('enviado_en')->nullable();
            $table->timestamp('calificado_en')->nullable();
            $table->unsignedTinyInteger('calificacion')->nullable();
            $table->boolean('aprobado')->nullable();
            $table->timestamps();

            $table->unique(['cuestionario_id', 'user_id', 'numero_intento'], 'intentos_cuestionario_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intentos_cuestionario');
    }
};
