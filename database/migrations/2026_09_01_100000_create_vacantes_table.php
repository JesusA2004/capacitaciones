<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacantes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('gerente_solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsable_rh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo', 30);
            $table->string('estado', 30)->default('abierta');
            $table->date('fecha_apertura');
            $table->date('fecha_estimada_cobertura')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacantes');
    }
};
