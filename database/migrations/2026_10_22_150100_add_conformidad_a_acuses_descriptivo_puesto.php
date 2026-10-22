<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acuse del descriptivo de puesto con las dos confirmaciones que pide RH:
 *
 *  - enterado_texto / enterado_en: el texto EXACTO de la casilla obligatoria
 *    («Enterado(a) de las actividades y responsabilidades de mi puesto…»)
 *    y cuándo la marcó — auditable aunque después cambie la redacción.
 *  - conformidad: lo que la persona escribió en «Estoy de acuerdo con…».
 *
 * Las apps instaladas siguen mandando `enterado` + `comentario`: en ese caso
 * el comentario se guarda también como conformidad
 * (AcuseDescriptivoPuestoService::acusar()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acuses_descriptivo_puesto', function (Blueprint $table): void {
            $table->string('enterado_texto', 500)->nullable()->after('enterado');
            $table->timestamp('enterado_en')->nullable()->after('enterado_texto');
            $table->text('conformidad')->nullable()->after('comentario');
        });
    }

    public function down(): void
    {
        Schema::table('acuses_descriptivo_puesto', function (Blueprint $table): void {
            $table->dropColumn(['enterado_texto', 'enterado_en', 'conformidad']);
        });
    }
};
