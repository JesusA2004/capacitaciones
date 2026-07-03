<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cumplimiento (Fase 9, ver docs/AUDITORIA_CUMPLIMIENTO.md
 * sección 12): las evidencias de entregas de actividad se guardaban como
 * RecursoMultimedia normal, indistinguible de un archivo de la biblioteca
 * administrativa. Estas columnas permiten separar ambos casos sin duplicar
 * la tabla ni el servicio de almacenamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recursos_multimedia', function (Blueprint $table) {
            $table->string('origen', 20)->default('biblioteca')->after('tipo');
            $table->string('visibilidad', 20)->default('publica')->after('origen');
            $table->foreignId('propietario_id')->nullable()->after('subido_por')->constrained('users')->nullOnDelete();
            $table->boolean('acceso_restringido')->default(false)->after('propietario_id');

            $table->index(['origen', 'visibilidad'], 'recursos_multimedia_origen_visibilidad_idx');
        });
    }

    public function down(): void
    {
        Schema::table('recursos_multimedia', function (Blueprint $table) {
            $table->dropIndex('recursos_multimedia_origen_visibilidad_idx');
            $table->dropConstrainedForeignId('propietario_id');
            $table->dropColumn(['origen', 'visibilidad', 'acceso_restringido']);
        });
    }
};
