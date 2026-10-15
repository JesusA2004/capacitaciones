<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de presentación de la Inducción Mr. Lana (RH la presenta en
 * persona o por Meet). ANTES de iniciar, RH elige a quién se la presenta;
 * al marcarla presentada, cada asistente queda con su contenido
 * institucional visto «por presentación de RH» y su evaluación oficial
 * (FO-RH-EI) habilitada — ver App\Services\Onboarding\SesionInduccionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_induccion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('presentada_por')->constrained('users');
            $table->string('estado', 30);
            $table->text('notas')->nullable();
            $table->timestamp('iniciada_en');
            $table->timestamp('presentada_en')->nullable();
            $table->timestamp('cancelada_en')->nullable();
            $table->timestamps();

            $table->index(['presentada_por', 'estado'], 'sesiones_induccion_presentador_estado_idx');
        });

        Schema::create('sesion_induccion_colaboradores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sesion_induccion_id')->constrained('sesiones_induccion')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            // Qué pasó con este asistente al marcar la sesión presentada
            // (App\Enums\ResultadoAsistenciaInduccion); null mientras la
            // sesión sigue en curso.
            $table->string('resultado', 40)->nullable();
            $table->foreignId('evaluacion_induccion_id')->nullable()->constrained('evaluaciones_induccion')->nullOnDelete();
            $table->timestamp('procesado_en')->nullable();
            $table->timestamps();

            $table->unique(['sesion_induccion_id', 'colaborador_id'], 'sesion_induccion_colab_unique');
            $table->index(['colaborador_id', 'resultado'], 'sesion_induccion_colab_resultado_idx');
        });

        // Comentario de RH al calificar los casos prácticos (sección D): va
        // en la pantalla de revisión y en el PDF del formato FO-RH-EI.
        Schema::table('evaluacion_induccion_intentos', function (Blueprint $table): void {
            $table->text('comentario_rh')->nullable()->after('desglose_d');
        });
    }

    public function down(): void
    {
        Schema::table('evaluacion_induccion_intentos', function (Blueprint $table): void {
            $table->dropColumn('comentario_rh');
        });

        Schema::dropIfExists('sesion_induccion_colaboradores');
        Schema::dropIfExists('sesiones_induccion');
    }
};
