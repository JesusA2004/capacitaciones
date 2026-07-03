<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banco_pregunta_id')->constrained('bancos_preguntas')->cascadeOnDelete();
            $table->text('enunciado');
            $table->string('tipo');
            $table->unsignedInteger('puntos')->default(1);
            $table->text('explicacion')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preguntas');
    }
};
