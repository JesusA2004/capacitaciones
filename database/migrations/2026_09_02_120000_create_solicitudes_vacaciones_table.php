<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_vacaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedSmallInteger('dias_solicitados');
            $table->text('comentario')->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisado_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_vacaciones');
    }
};
