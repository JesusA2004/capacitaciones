<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compensación centralizada (CompensacionService): `salario_diario` /
 * `salario_diario_integrado` son la fuente real desde el importador de
 * Excel (docs/onboarding/REPORTE_TRABAJADORES_VAC.xlsx) y cualquier cambio
 * manual — `sueldo_mensual` se sigue guardando (lo usan contratos y
 * documentos ya existentes) pero SIEMPRE como diario×30, nunca editado
 * aparte. `historial_salarios` es un ledger append-only: una corrección
 * nunca edita/borra una fila, agrega una nueva (CLAUDE.md "nunca sobrescribir
 * sin rastro").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->decimal('salario_diario', 10, 2)->nullable()->after('sueldo_mensual');
            $table->decimal('salario_diario_integrado', 10, 2)->nullable()->after('salario_diario');
        });

        Schema::create('historial_salarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->decimal('salario_diario_anterior', 10, 2)->nullable();
            $table->decimal('salario_diario_nuevo', 10, 2);
            $table->decimal('salario_diario_integrado_anterior', 10, 2)->nullable();
            $table->decimal('salario_diario_integrado_nuevo', 10, 2)->nullable();
            $table->date('fecha_efectiva');
            $table->string('fuente', 40);
            $table->foreignId('importacion_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->index(['colaborador_id', 'fecha_efectiva'], 'historial_salarios_colaborador_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_salarios');

        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropColumn(['salario_diario', 'salario_diario_integrado']);
        });
    }
};
