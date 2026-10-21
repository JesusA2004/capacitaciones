<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correcciones de datos con seguimiento (docs/SOLICITUDES_UNIFICADAS.md,
 * «Correcciones solicitadas por el colaborador»):
 *
 *  - `solicitud_interna_historial`: de dónde vino cada movimiento (web,
 *    app o sistema), IP y user agent, para saber quién resolvió, cuándo y
 *    desde dónde sin depender solo del activity log.
 *  - `solicitudes_internas`: los datos médicos propuestos (y los que había
 *    antes de aplicarlos) van CIFRADOS en sus propias columnas, nunca en el
 *    JSON en claro de `datos_propuestos`.
 *
 * Solo agrega columnas nulas: no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitud_interna_historial', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitud_interna_historial', 'origen')) {
                $table->string('origen', 20)->nullable()->after('comentario');
            }

            if (! Schema::hasColumn('solicitud_interna_historial', 'ip')) {
                $table->string('ip', 45)->nullable()->after('origen');
            }

            if (! Schema::hasColumn('solicitud_interna_historial', 'user_agent')) {
                $table->string('user_agent', 500)->nullable()->after('ip');
            }
        });

        Schema::table('solicitudes_internas', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitudes_internas', 'datos_sensibles_propuestos')) {
                $table->text('datos_sensibles_propuestos')->nullable()->after('datos_base');
            }

            if (! Schema::hasColumn('solicitudes_internas', 'datos_sensibles_anteriores')) {
                $table->text('datos_sensibles_anteriores')->nullable()->after('datos_sensibles_propuestos');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitud_interna_historial', function (Blueprint $table) {
            $table->dropColumn(['origen', 'ip', 'user_agent']);
        });

        Schema::table('solicitudes_internas', function (Blueprint $table) {
            $table->dropColumn(['datos_sensibles_propuestos', 'datos_sensibles_anteriores']);
        });
    }
};
