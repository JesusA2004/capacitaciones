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
            // Campos que unifican Vacaciones, Préstamo interno y Baja de
            // colaborador dentro de esta tabla (docs/SOLICITUDES_UNIFICADAS.md).
            $table->foreignId('colaborador_objetivo_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('dias_solicitados')->nullable();
            $table->decimal('monto_solicitado', 10, 2)->nullable();
            $table->unsignedSmallInteger('plazo_meses')->nullable();
            // Datos operativos propios de una solicitud de baja de colaborador:
            // fecha en la que la baja surte efecto y clasificación del tipo de
            // baja (para reportes de rotación).
            $table->date('fecha_efectiva')->nullable();
            $table->string('tipo_baja', 30)->nullable();
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
