<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que el FORMATO PERMISO MR. LANA (PDF oficial) imprime y que la
 * solicitud interna no guardaba: hora de salida, hora de entrada y la
 * modalidad del permiso (tiempo x tiempo / descuento vía nómina / permiso
 * especial). Opcionales: el formato marca solo lo que el flujo conoce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->time('hora_salida')->nullable()->after('fecha_fin');
            $table->time('hora_entrada')->nullable()->after('hora_salida');
            $table->string('modalidad_permiso', 30)->nullable()->after('hora_entrada');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->dropColumn(['hora_salida', 'hora_entrada', 'modalidad_permiso']);
        });
    }
};
