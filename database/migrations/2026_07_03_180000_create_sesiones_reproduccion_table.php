<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_reproduccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leccion_id')->constrained('lecciones')->cascadeOnDelete();
            $table->foreignId('recurso_multimedia_id')->constrained('recursos_multimedia')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('iniciada_en');
            $table->timestamp('ultimo_heartbeat_en')->nullable();
            $table->unsignedInteger('ultima_posicion_segundos')->default(0);
            $table->timestamp('finalizada_en')->nullable();
            $table->boolean('completada')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'leccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_reproduccion');
    }
};
