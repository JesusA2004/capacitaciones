<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos «Completa tu información» que RH envía a un colaborador con datos
 * contractuales faltantes: snapshot de los campos que faltaban, quién avisó
 * y cuándo (también sirve para no mandar el mismo aviso varias veces al día).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_datos_faltantes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->json('campos');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['colaborador_id', 'created_at'], 'avisos_datos_faltantes_colab_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_datos_faltantes');
    }
};
