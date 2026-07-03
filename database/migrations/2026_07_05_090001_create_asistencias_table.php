<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_en_vivo_id')->constrained('sesiones_en_vivo')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('estado')->default('pendiente');
            $table->timestamp('unido_en')->nullable();
            $table->timestamp('salido_en')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();
            $table->foreignId('corregido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_correccion')->nullable();
            $table->timestamps();

            $table->unique(['sesion_en_vivo_id', 'user_id'], 'asistencias_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
