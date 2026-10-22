<?php

namespace Database\Seeders;

use App\Enums\ClaveFormularioEvaluacion;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Database\Seeder;

/**
 * Versión 1 (publicada) de los formularios de evaluación configurables,
 * copiada TAL CUAL de config/induccion_evaluacion.php y
 * config/contratos.php (evaluacion_periodo_prueba_v2): el comportamiento es
 * idéntico al de antes de existir el módulo. Idempotente y seguro en
 * producción: si la clave ya tiene cualquier versión, no toca nada.
 */
class FormulariosEvaluacionSeeder extends Seeder
{
    public function run(FormularioEvaluacionService $formularios): void
    {
        foreach (ClaveFormularioEvaluacion::cases() as $clave) {
            $formularios->asegurarVersionInicial($clave);
        }
    }
}
