<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cursos que deben completarse antes de poder inscribirse a este curso.
     */
    public function up(): void
    {
        Schema::create('curso_requisito_previo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();
            $table->foreignId('requisito_curso_id')->constrained('cursos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['curso_id', 'requisito_curso_id'], 'curso_requisito_previo_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curso_requisito_previo');
    }
};
