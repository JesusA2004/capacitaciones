<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscripciones_curso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();
            $table->foreignId('asignacion_usuario_id')->nullable()->constrained('asignaciones_usuario')->nullOnDelete();
            $table->string('estado')->default('pendiente');
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('completado_en')->nullable();
            $table->unsignedTinyInteger('calificacion_final')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'curso_id'], 'inscripciones_curso_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscripciones_curso');
    }
};
