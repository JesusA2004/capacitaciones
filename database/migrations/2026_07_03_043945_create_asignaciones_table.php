<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('asignable_type');
            $table->unsignedBigInteger('asignable_id');
            $table->index(['asignable_type', 'asignable_id']);
            $table->foreignId('responsable_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('fecha_inicio')->nullable();
            $table->dateTime('fecha_limite')->nullable();
            $table->boolean('obligatoria')->default(true);
            $table->json('recordatorios')->nullable();
            $table->json('reglas')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamp('cancelada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones');
    }
};
