<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_internas', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('estado', 20)->default('creada');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('motivo');
            $table->text('observaciones')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisado_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            // Snapshot de empresa/sucursal al momento de crear la solicitud:
            // permite reutilizar AlcanceOrganizacionalService::limitarPorSucursal()
            // sin depender de que el colaborador siga en la misma sucursal.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'estado']);
            $table->index(['tipo', 'estado']);
            $table->index('sucursal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_internas');
    }
};
