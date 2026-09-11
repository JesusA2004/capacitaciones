<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos operativos propios de una solicitud de baja de colaborador
 * (docs/SOLICITUDES_UNIFICADAS.md): el motivo/observaciones generales ya
 * existen en la solicitud (motivo/observaciones), estas dos columnas son
 * las que NO tenían equivalente — fecha en la que la baja surte efecto y
 * clasificación del tipo de baja (para reportes de rotación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->date('fecha_efectiva')->nullable()->after('colaborador_objetivo_id');
            $table->string('tipo_baja', 30)->nullable()->after('fecha_efectiva');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->dropColumn(['fecha_efectiva', 'tipo_baja']);
        });
    }
};
