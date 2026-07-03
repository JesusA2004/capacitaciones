<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respuestas_cuestionario', function (Blueprint $table) {
            // Respuesta de una pregunta tipo "escala".
            $table->unsignedTinyInteger('valor_numerico')->nullable()->after('respuesta_texto');
            // Respuesta de una pregunta tipo "carga_archivo": reutiliza
            // RecursoMultimedia/MediaStorageService (origen=cuestionario,
            // acceso_restringido=true), igual que EntregaActividad.
            $table->foreignId('recurso_multimedia_id')->nullable()->after('valor_numerico')
                ->constrained('recursos_multimedia')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('respuestas_cuestionario', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurso_multimedia_id');
            $table->dropColumn('valor_numerico');
        });
    }
};
