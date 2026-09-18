<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger APPEND-ONLY de abonos a un préstamo (ver App\Services\Nomina\PrestamoService::registrarMovimiento()).
 * Nunca se actualiza ni se borra un movimiento ya creado — es el registro
 * auditable de cómo se llegó al saldo actual del préstamo, así que cada fila
 * congela `saldo_anterior`/`saldo_nuevo` en el momento en que se registró,
 * sin depender de recalcular hacia atrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamo_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->string('tipo', 20);
            $table->decimal('saldo_anterior', 10, 2);
            $table->decimal('saldo_nuevo', 10, 2);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['prestamo_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamo_movimientos');
    }
};
