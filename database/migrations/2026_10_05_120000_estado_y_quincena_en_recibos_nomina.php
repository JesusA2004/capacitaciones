<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos quincenales automáticos (docs/NOMINA_QUINCENAL.md): el sistema
 * prepara los recibos de la quincena como BORRADOR unos días antes del
 * pago, RH los ajusta (uno por uno o en bloque) y en la fecha de pago se
 * EMITEN solos (PDF + aviso al colaborador). El colaborador solo ve
 * recibos emitidos. Los recibos que ya existían quedan como emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->string('estado', 20)->default('emitido')->after('neto');
            $table->timestamp('emitido_at')->nullable()->after('estado');
            $table->index(['estado', 'fecha_pago'], 'recibos_nomina_estado_pago_idx');
            $table->index(['tipo_periodo', 'periodo_inicio'], 'recibos_nomina_tipo_periodo_idx');
        });

        // Los recibos históricos ya se entregaron: emitidos en su fecha.
        DB::table('recibos_nomina')->whereNull('emitido_at')->update(['emitido_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('recibos_nomina', function (Blueprint $table): void {
            $table->dropIndex('recibos_nomina_estado_pago_idx');
            $table->dropIndex('recibos_nomina_tipo_periodo_idx');
            $table->dropColumn(['estado', 'emitido_at']);
        });
    }
};
