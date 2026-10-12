<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluación de Término de Periodo de Prueba — rediseño contra el formato
 * oficial (docs/onboarding/Evaluacion_Periodo_de_Prueba_Operativo.pdf,
 * FO-RH-EPP-001): 9 criterios (escala 4-1), 5 preguntas filtro Sí/No y la
 * excepción formal de Dirección General. `version` null/1 en
 * evaluaciones_periodo_prueba sigue siendo el formato libre legacy (7
 * criterios sugeridos, calificación 0-10) — nunca se reinterpreta con las
 * reglas nuevas (CLAUDE.md "legacy no se rompe").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_excepcion_periodo_prueba', function (Blueprint $table): void {
            $table->id();
            // Nombre de constraint explícito: el autogenerado excede los 64
            // caracteres de MariaDB (error 1071/1059).
            $table->foreignId('evaluacion_periodo_prueba_id')
                ->constrained('evaluaciones_periodo_prueba', indexName: 'sol_excepcion_epp_evaluacion_fk')
                ->cascadeOnDelete();
            $table->text('motivo');
            $table->string('documento_soporte_path')->nullable();
            $table->foreignId('solicitante_id')->constrained('users')->cascadeOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_en')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->timestamps();

            $table->index(['evaluacion_periodo_prueba_id', 'estado'], 'solicitudes_excepcion_epp_evaluacion_estado_idx');
        });

        Schema::table('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            // null/1 = formato libre legacy; 2 = formato oficial FO-RH-EPP-001.
            $table->unsignedTinyInteger('version')->nullable()->after('criterios');
            $table->json('filtros')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('evaluaciones_periodo_prueba', function (Blueprint $table): void {
            $table->dropColumn(['version', 'filtros']);
        });

        Schema::dropIfExists('solicitudes_excepcion_periodo_prueba');
    }
};
