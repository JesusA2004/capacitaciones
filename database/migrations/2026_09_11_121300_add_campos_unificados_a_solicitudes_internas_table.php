<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que unifican Vacaciones, Préstamo interno y Baja de colaborador
 * dentro de `solicitudes_internas` (docs/SOLICITUDES_UNIFICADAS.md). No se
 * toca `solicitudes_vacaciones`: al momento de unificar estaba vacía (sin
 * datos que migrar), se conserva la tabla intacta sin uso nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_solicitados')->nullable()->after('fecha_fin');
            $table->foreignId('colaborador_objetivo_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->decimal('monto_solicitado', 10, 2)->nullable()->after('dias_solicitados');
            $table->unsignedSmallInteger('plazo_meses')->nullable()->after('monto_solicitado');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('colaborador_objetivo_id');
            $table->dropColumn(['dias_solicitados', 'monto_solicitado', 'plazo_meses']);
        });
    }
};
