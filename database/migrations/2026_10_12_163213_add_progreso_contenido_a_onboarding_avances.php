<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progreso granular de contenido (capítulos de la Inducción Mr. Lana) por
 * avance: {capitulos_vistos: [clave,...], ultima_capitulo: clave, porcentaje: int}.
 * Aditivo y nullable — no cambia el gate existente (contenido_visto_en sigue
 * siendo la única fuente de verdad de "contenido completo"; este campo solo
 * permite retomar exactamente donde quedó y mostrar "8/30").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_avances', function (Blueprint $table): void {
            $table->json('progreso_contenido')->nullable()->after('contenido_visto_en');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_avances', function (Blueprint $table): void {
            $table->dropColumn('progreso_contenido');
        });
    }
};
