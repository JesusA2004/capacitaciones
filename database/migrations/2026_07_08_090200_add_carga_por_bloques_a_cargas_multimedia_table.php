<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cumplimiento (Fase 9, sección 7): `cargas_multimedia` existía
 * desde la Fase 3 pero ningún código la usaba (tabla fantasma); la carga
 * real era un único request con el archivo completo. Estas columnas
 * habilitan la sesión de carga por bloques reanudable real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargas_multimedia', function (Blueprint $table) {
            $table->uuid('identificador')->unique()->after('id');
            $table->string('tipo', 20)->default('video')->after('nombre_original');
            $table->unsignedInteger('tamano_bloque_bytes')->nullable()->after('tamano_total_bytes');
            $table->unsignedInteger('total_bloques')->nullable()->after('tamano_bloque_bytes');
            $table->json('bloques_recibidos')->nullable()->after('bytes_recibidos');
            $table->string('hash_esperado', 64)->nullable()->after('bloques_recibidos');
            $table->string('hash_calculado', 64)->nullable()->after('hash_esperado');
            $table->timestamp('expira_en')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('cargas_multimedia', function (Blueprint $table) {
            $table->dropColumn([
                'identificador', 'tipo', 'tamano_bloque_bytes', 'total_bloques',
                'bloques_recibidos', 'hash_esperado', 'hash_calculado', 'expira_en',
            ]);
        });
    }
};
