<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluaciones técnicas por puesto («Seguimiento 15 / 30 / 60…», Fase 2,
 * docs/EVALUACIONES_TECNICAS.md):
 *
 *  - plantillas_evaluacion_tecnica: formularios técnicos reutilizables. Sus
 *    versiones (borrador/publicado/archivado) viven en formularios_evaluacion
 *    con clave 'tecnica' — el MISMO constructor tipo Google Forms.
 *  - pasos_evaluacion_tecnica: calendario por puesto (día N desde el ingreso,
 *    qué plantilla, quién contesta, mínima aprobatoria).
 *  - evaluaciones_tecnicas: cada evaluación generada a un colaborador.
 *
 * formularios_evaluacion gana `familia` (historial de versiones: la clave
 * para inducción/periodo de prueba, «tecnica_{id}» para cada plantilla) y el
 * único (clave, version) pasa a (familia, version). Aditiva: las filas
 * existentes quedan con familia = clave, idéntico a hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantillas_evaluacion_tecnica', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 160);
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('formularios_evaluacion', function (Blueprint $table) {
            $table->string('familia', 40)->nullable()->after('clave');
            $table->foreignId('plantilla_evaluacion_tecnica_id')->nullable()->after('familia')
                ->constrained('plantillas_evaluacion_tecnica')->nullOnDelete();
        });

        DB::table('formularios_evaluacion')->whereNull('familia')->update(['familia' => DB::raw('clave')]);

        Schema::table('formularios_evaluacion', function (Blueprint $table) {
            $table->dropUnique('formularios_evaluacion_clave_version_unique');
            $table->unique(['familia', 'version'], 'formularios_evaluacion_familia_version_unique');
            $table->index(['familia', 'estado'], 'formularios_evaluacion_familia_estado_idx');
        });

        Schema::create('pasos_evaluacion_tecnica', function (Blueprint $table) {
            $table->id();
            $table->foreignId('puesto_id')->constrained('puestos')->cascadeOnDelete();
            $table->unsignedSmallInteger('dias');
            $table->string('nombre', 120);
            $table->foreignId('plantilla_evaluacion_tecnica_id')->nullable()
                ->constrained('plantillas_evaluacion_tecnica')->nullOnDelete();
            $table->string('contesta', 20)->default('colaborador');
            $table->decimal('calificacion_minima', 5, 2)->default(80);
            $table->unsignedSmallInteger('dias_para_contestar')->default(7);
            $table->boolean('es_final')->default(false);
            $table->boolean('activo')->default(true);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['puesto_id', 'dias'], 'pasos_evaltec_puesto_dias_unique');
        });

        Schema::create('evaluaciones_tecnicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
            $table->foreignId('paso_evaluacion_tecnica_id')->nullable()
                ->constrained('pasos_evaluacion_tecnica')->nullOnDelete();
            $table->foreignId('plantilla_evaluacion_tecnica_id')->nullable()
                ->constrained('plantillas_evaluacion_tecnica')->nullOnDelete();
            $table->foreignId('formulario_evaluacion_id')->nullable()
                ->constrained('formularios_evaluacion')->nullOnDelete();
            $table->string('nombre', 120);
            $table->unsignedSmallInteger('dias');
            $table->boolean('es_final')->default(false);
            $table->date('fecha_programada');
            $table->date('fecha_limite');
            $table->string('contesta', 20);
            // Jefe directo del organigrama (colaboradores.jefe_id) al generarse.
            $table->foreignId('evaluador_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->decimal('calificacion_minima', 5, 2);
            $table->string('estado', 20)->default('pendiente');
            $table->json('respuestas')->nullable();
            $table->decimal('puntos_obtenidos', 8, 2)->nullable();
            $table->decimal('puntos_totales', 8, 2)->nullable();
            $table->decimal('calificacion', 5, 2)->nullable();
            $table->boolean('aprobada')->nullable();
            $table->text('comentarios')->nullable();
            $table->timestamp('contestada_en')->nullable();
            $table->foreignId('contestada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelada_en')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            // Idempotencia del programador: una evaluación por colaborador,
            // puesto y día del calendario.
            $table->unique(['colaborador_id', 'puesto_id', 'dias'], 'evaltec_colab_puesto_dias_unique');
            $table->index(['estado', 'fecha_limite'], 'evaltec_estado_limite_idx');
            $table->index(['contestada_en'], 'evaltec_contestada_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluaciones_tecnicas');
        Schema::dropIfExists('pasos_evaluacion_tecnica');

        DB::table('formularios_evaluacion')->where('clave', 'tecnica')->delete();

        Schema::table('formularios_evaluacion', function (Blueprint $table) {
            $table->dropIndex('formularios_evaluacion_familia_estado_idx');
            $table->dropUnique('formularios_evaluacion_familia_version_unique');
            $table->unique(['clave', 'version'], 'formularios_evaluacion_clave_version_unique');
            $table->dropConstrainedForeignId('plantilla_evaluacion_tecnica_id');
            $table->dropColumn('familia');
        });

        Schema::dropIfExists('plantillas_evaluacion_tecnica');
    }
};
