<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuestionario_pregunta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuestionario_id')->constrained('cuestionarios')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->constrained('preguntas')->cascadeOnDelete();
            $table->unsignedInteger('orden')->default(0);
            $table->unsignedInteger('puntos')->nullable();
            $table->timestamps();

            $table->unique(['cuestionario_id', 'pregunta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuestionario_pregunta');
    }
};
