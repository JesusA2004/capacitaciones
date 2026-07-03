<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración específica de los tipos de pregunta agregados en la Fase 9
 * (docs/AUDITORIA_CUMPLIMIENTO.md sección 11): escala (rango + etiquetas) y
 * carga_archivo (extensiones/tamaño permitidos). respuesta_larga no necesita
 * columnas propias, reutiliza el mismo flujo de texto libre que
 * respuesta_corta pero con revisión manual y un límite de caracteres mayor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preguntas', function (Blueprint $table) {
            $table->unsignedTinyInteger('escala_min')->nullable()->after('explicacion');
            $table->unsignedTinyInteger('escala_max')->nullable()->after('escala_min');
            $table->string('escala_etiqueta_min')->nullable()->after('escala_max');
            $table->string('escala_etiqueta_max')->nullable()->after('escala_etiqueta_min');
            $table->json('extensiones_permitidas')->nullable()->after('escala_etiqueta_max');
            $table->unsignedInteger('tamano_maximo_mb')->nullable()->after('extensiones_permitidas');
        });
    }

    public function down(): void
    {
        Schema::table('preguntas', function (Blueprint $table) {
            $table->dropColumn([
                'escala_min', 'escala_max', 'escala_etiqueta_min', 'escala_etiqueta_max',
                'extensiones_permitidas', 'tamano_maximo_mb',
            ]);
        });
    }
};
