<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relacion "puede cubrir a": puesto_id puede cubrir/respaldar a
     * puesto_a_cubrir_id (por ejemplo, Subgerente puede cubrir a Gerente).
     * Es de muchos a muchos porque un puesto puede tener varios respaldos y
     * un mismo puesto puede respaldar a varios puestos distintos.
     */
    public function up(): void
    {
        Schema::create('puesto_cobertura', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            $table->foreignId('puesto_a_cubrir_id')->constrained('puestos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['puesto_id', 'puesto_a_cubrir_id'], 'puesto_cobertura_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puesto_cobertura');
    }
};
