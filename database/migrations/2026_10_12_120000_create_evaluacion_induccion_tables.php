<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluación oficial del Programa de Inducción (FO-RH-EI, 30 puntos,
 * docs/onboarding/Evaluacion_Induccion_Mr_Lana 2026.pdf). No se mezcla con el
 * quiz genérico de onboarding_modulos: un módulo institucional marcado con
 * evaluacion_formal_clave deja de usar su propio "preguntas" (ver
 * App\Services\Onboarding\EvaluacionInduccionService) y en su lugar lo
 * aprueba esta evaluación formal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_modulos', function (Blueprint $table): void {
            // Si no es null, el avance de este módulo NUNCA se aprueba con el
            // quiz genérico: lo aprueba la evaluación formal de esa clave
            // (hoy solo 'induccion_mr_lana_2026', ver config/induccion_evaluacion.php).
            $table->string('evaluacion_formal_clave', 80)->nullable()->after('preguntas');
        });

        Schema::table('onboarding_avances', function (Blueprint $table): void {
            // Contenido visto por el colaborador (independiente de aprobado_en,
            // que es la calificación). Hoy solo lo usa el gate de "contenido
            // obligatorio completo" antes de habilitar la evaluación formal;
            // la Fase de presentación interactiva de inducción lo reutilizará
            // para marcar progreso por sección.
            $table->timestamp('contenido_visto_en')->nullable()->after('aprobado_en');
        });

        Schema::create('evaluaciones_induccion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_proceso_id')->constrained('onboarding_procesos')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('clave', 80);
            $table->string('version', 60);
            $table->string('estado', 30);
            $table->unsignedSmallInteger('intentos_count')->default(0);
            $table->decimal('ultima_calificacion', 5, 2)->nullable();
            $table->decimal('mejor_calificacion', 5, 2)->nullable();
            $table->timestamp('aprobada_en')->nullable();
            $table->text('retroalimentacion')->nullable();
            $table->foreignId('retroalimentado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retroalimentado_en')->nullable();
            // Plazo máximo para reaplicar tras reforzamiento (5 días hábiles).
            $table->date('fecha_limite_reevaluacion')->nullable();
            $table->timestamps();

            $table->unique(['onboarding_proceso_id', 'clave'], 'evaluaciones_induccion_proceso_clave_unique');
            $table->index('estado');
        });

        Schema::create('evaluacion_induccion_intentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluacion_induccion_id')->constrained('evaluaciones_induccion')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            // Respuestas crudas del colaborador: {a: {0: idx,...}, b: {0: bool,...}, c: {0: 'A',...}, d: {caso_26: texto, caso_27: texto}}.
            $table->json('respuestas');
            // {claridad: 1-5, conocimiento_puesto: 1-5, bienvenida: 1-5, tema_reforzar: texto|null} — no califica.
            $table->json('opinion')->nullable();
            $table->decimal('calificacion_a', 4, 2);
            $table->decimal('calificacion_b', 4, 2);
            $table->decimal('calificacion_c', 4, 2);
            // null hasta que RH califica los casos prácticos.
            $table->decimal('calificacion_d', 4, 2)->nullable();
            $table->json('desglose_d')->nullable();
            $table->decimal('calificacion_total', 5, 2)->nullable();
            $table->string('nivel', 30)->nullable();
            $table->boolean('aprobado')->nullable();
            $table->text('retroalimentacion_previa')->nullable();
            $table->foreignId('presentado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('presentado_en');
            $table->foreignId('calificado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calificado_en')->nullable();
            $table->timestamps();

            $table->unique(['evaluacion_induccion_id', 'numero'], 'evaluacion_induccion_intentos_eval_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluacion_induccion_intentos');
        Schema::dropIfExists('evaluaciones_induccion');

        Schema::table('onboarding_avances', function (Blueprint $table): void {
            $table->dropColumn('contenido_visto_en');
        });

        Schema::table('onboarding_modulos', function (Blueprint $table): void {
            $table->dropColumn('evaluacion_formal_clave');
        });
    }
};
