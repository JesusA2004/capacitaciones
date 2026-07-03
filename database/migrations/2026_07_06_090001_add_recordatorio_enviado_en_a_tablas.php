<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de cuando se envio el ultimo recordatorio automatico, para que
     * los comandos programados (Fase 7) no vuelvan a notificar por la misma
     * fecha limite/sesion en cada ejecucion del scheduler.
     */
    public function up(): void
    {
        Schema::table('asignaciones_usuario', function (Blueprint $table) {
            $table->timestamp('recordatorio_enviado_en')->nullable()->after('completado_en');
        });

        Schema::table('sesiones_en_vivo', function (Blueprint $table) {
            $table->timestamp('recordatorio_enviado_en')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('asignaciones_usuario', function (Blueprint $table) {
            $table->dropColumn('recordatorio_enviado_en');
        });

        Schema::table('sesiones_en_vivo', function (Blueprint $table) {
            $table->dropColumn('recordatorio_enviado_en');
        });
    }
};
