<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importador de docs/onboarding/REPORTE_TRABAJADORES_VAC.xlsx (salarios y
 * vacaciones históricas). Flujo obligatorio: subir → analizar (preview,
 * `detalle` guarda cada fila ya resuelta) → revisar → aplicar. Mismo
 * archivo (por hash) nunca se aplica dos veces. `ajustes_historicos_vacaciones`
 * es un ledger append-only (igual que historial_salarios): una corrección
 * nunca edita/borra una fila, agrega una nueva con el valor inverso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importaciones_excel', function (Blueprint $table): void {
            $table->id();
            $table->string('archivo_hash', 64)->unique();
            $table->string('nombre_archivo');
            $table->string('tipo', 40)->default('trabajadores_salarios_vacaciones');
            $table->foreignId('subido_por')->constrained('users')->cascadeOnDelete();
            $table->string('estado', 20)->default('previsualizado');
            $table->unsignedInteger('total_filas')->default(0);
            $table->unsignedInteger('filas_aplicadas')->default(0);
            $table->unsignedInteger('filas_revision')->default(0);
            $table->unsignedInteger('filas_sin_cambios')->default(0);
            $table->unsignedInteger('filas_no_encontradas')->default(0);
            $table->unsignedInteger('filas_error')->default(0);
            $table->json('detalle')->nullable();
            $table->timestamp('aplicado_en')->nullable();
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('historial_salarios', function (Blueprint $table): void {
            $table->foreign('importacion_id', 'historial_salarios_importacion_fk')
                ->references('id')->on('importaciones_excel')->nullOnDelete();
        });

        Schema::create('ajustes_historicos_vacaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->decimal('dias', 6, 2);
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fin')->nullable();
            $table->date('fecha_corte');
            $table->string('fuente', 40);
            $table->foreignId('importacion_id')->nullable()
                ->constrained('importaciones_excel', indexName: 'ajustes_hist_vac_importacion_fk')
                ->nullOnDelete();
            $table->text('motivo')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['colaborador_id', 'fecha_corte'], 'ajustes_hist_vac_colaborador_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_historicos_vacaciones');

        Schema::table('historial_salarios', function (Blueprint $table): void {
            $table->dropForeign('historial_salarios_importacion_fk');
        });

        Schema::dropIfExists('importaciones_excel');
    }
};
