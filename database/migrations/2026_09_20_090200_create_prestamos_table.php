<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préstamo interno real de un colaborador (ver App\Services\Nomina\PrestamoService).
 * Nace casi siempre de una SolicitudInterna de tipo `prestamo` ya aprobada
 * (`solicitud_id`), aunque el campo es nullable para permitir un préstamo
 * capturado directamente por RH sin pasar por el flujo de solicitudes.
 * `saldo` es el saldo vivo (se actualiza con cada PrestamoMovimiento, ver
 * esa migración); `estado` sigue pendiente_entrega hasta que RH confirma que
 * el dinero ya se entregó (PrestamoService::activar()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('solicitud_id')->nullable()->constrained('solicitudes_internas')->nullOnDelete();
            $table->decimal('monto_original', 10, 2);
            $table->decimal('saldo', 10, 2);
            $table->unsignedSmallInteger('plazo');
            $table->string('periodicidad', 20);
            $table->decimal('pago_programado', 10, 2);
            $table->date('fecha_otorgamiento')->nullable();
            $table->date('fecha_primer_descuento')->nullable();
            $table->string('estado', 20)->default('pendiente_entrega');
            $table->timestamps();

            $table->index(['colaborador_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamos');
    }
};
