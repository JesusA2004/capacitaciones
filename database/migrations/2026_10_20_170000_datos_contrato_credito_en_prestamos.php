<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Contrato de crédito para trabajadores» (sección I. Datos del contrato):
 * casillas que RH captura A MANO al autorizar el préstamo y que antes
 * quedaban en blanco porque el préstamo no las guardaba. Ninguna se
 * calcula: lo que RH captura es lo que se imprime.
 *
 *  - cargo_sin_iva / iva / monto_total_con_iva: «Cargo (sin I.V.A.)»,
 *    «I.V.A.» y «Monto total (con I.V.A.)».
 *  - numero_cheque: «No. de cheque» (vacío si se entregó por transferencia).
 *  - fecha_ultimo_pago: «Último pago parcial» (el primero ya existía:
 *    fecha_primer_descuento).
 *
 * Todas opcionales: las versiones de la app que ya están instaladas
 * autorizan sin mandarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->decimal('cargo_sin_iva', 12, 2)->nullable()->after('pago_programado');
            $table->decimal('iva', 12, 2)->nullable()->after('cargo_sin_iva');
            $table->decimal('monto_total_con_iva', 12, 2)->nullable()->after('iva');
            $table->string('numero_cheque', 40)->nullable()->after('monto_total_con_iva');
            $table->date('fecha_ultimo_pago')->nullable()->after('fecha_primer_descuento');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn(['cargo_sin_iva', 'iva', 'monto_total_con_iva', 'numero_cheque', 'fecha_ultimo_pago']);
        });
    }
};
