<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formularios de evaluación configurables y VERSIONADOS (inducción FO-RH-EI y
 * periodo de prueba FO-RH-EPP-001). Una versión publicada es inmutable; editar
 * crea un borrador nuevo. Cada evaluación guarda con qué versión se contestó
 * (formulario_evaluacion_id; null = formato original de config/, el mismo
 * contenido que la versión 1). Ver App\Services\Evaluaciones\FormularioEvaluacionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formularios_evaluacion', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 30);
            $table->unsignedInteger('version');
            $table->string('codigo', 40);
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->json('diseno')->nullable();
            $table->json('definicion');
            $table->string('estado', 20)->default('borrador');
            $table->timestamp('publicado_en')->nullable();
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clave', 'version'], 'formularios_evaluacion_clave_version_unique');
            $table->index(['clave', 'estado'], 'formularios_evaluacion_clave_estado_idx');
        });

        Schema::table('evaluaciones_induccion', function (Blueprint $table): void {
            $table->foreignId('formulario_evaluacion_id')->nullable()->after('version')
                ->constrained('formularios_evaluacion', 'id', 'eval_induccion_formulario_fk')->nullOnDelete();
        });

        Schema::table('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            $table->foreignId('formulario_evaluacion_id')->nullable()->after('version')
                ->constrained('formularios_evaluacion', 'id', 'eval_periodo_formulario_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            $table->dropForeign('eval_periodo_formulario_fk');
            $table->dropColumn('formulario_evaluacion_id');
        });

        Schema::table('evaluaciones_induccion', function (Blueprint $table): void {
            $table->dropForeign('eval_induccion_formulario_fk');
            $table->dropColumn('formulario_evaluacion_id');
        });

        Schema::dropIfExists('formularios_evaluacion');
    }
};
