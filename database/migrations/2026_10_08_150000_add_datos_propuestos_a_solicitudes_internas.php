<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Completar mis datos» (actualización de datos): el colaborador propone
 * valores ESTRUCTURADOS (CURP, NSS, contacto de emergencia…) y solo al
 * autorizarlos RH se aplican al expediente. `datos_anteriores` guarda lo
 * que había antes de aplicar (auditoría; nada se pierde).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->json('datos_propuestos')->nullable()->after('observaciones');
            $table->json('datos_anteriores')->nullable()->after('datos_propuestos');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table): void {
            $table->dropColumn(['datos_propuestos', 'datos_anteriores']);
        });
    }
};
