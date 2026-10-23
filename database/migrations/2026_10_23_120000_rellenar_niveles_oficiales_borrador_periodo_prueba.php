<?php

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoFormularioEvaluacion;
use App\Models\FormularioEvaluacion;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige los BORRADORES de FO-RH-EPP-001 (periodo de prueba) cuyos criterios
 * oficiales quedaron SIN las descripciones de nivel 4/3/2/1 (p. ej. el
 * borrador v4 creado a partir de una versión publicada anterior a que el
 * catálogo tuviera `niveles`). Rellena SOLO lo que falta desde la config
 * oficial (FormularioEvaluacionService::rellenarCriteriosOficialesPeriodoPrueba),
 * sin pisar textos que RH ya editó.
 *
 * SOLO toca formularios en estado BORRADOR: las versiones publicadas
 * (históricas) y las evaluaciones ya contestadas NO se modifican. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('formularios_evaluacion')) {
            return;
        }

        $servicio = app(FormularioEvaluacionService::class);

        FormularioEvaluacion::query()
            ->where('clave', ClaveFormularioEvaluacion::PeriodoPrueba->value)
            ->where('estado', EstadoFormularioEvaluacion::Borrador->value)
            ->get()
            ->each(function (FormularioEvaluacion $formulario) use ($servicio): void {
                $definicion = $formulario->definicion;
                $rellenada = $servicio->rellenarCriteriosOficialesPeriodoPrueba($definicion);

                if ($rellenada !== $definicion) {
                    $formulario->update(['definicion' => $rellenada]);
                }
            });
    }

    public function down(): void
    {
        // Solo rellena valores faltantes en borradores: no se revierte.
    }
};
