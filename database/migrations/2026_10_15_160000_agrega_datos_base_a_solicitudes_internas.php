<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actualización de datos: lo que había en el expediente AL SOLICITAR cada
 * campo. Distingue «Completar dato faltante» (vacío) de «Solicitar cambio
 * de información» (ya tenía valor) y evita sobrescribir en silencio un dato
 * que cambió mientras RH revisaba. Las solicitudes previas quedan en null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table) {
            $table->json('datos_base')->nullable()->after('datos_anteriores');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table) {
            $table->dropColumn('datos_base');
        });
    }
};
