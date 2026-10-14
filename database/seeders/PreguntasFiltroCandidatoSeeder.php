<?php

namespace Database\Seeders;

use App\Enums\GrupoPuestoIndicador;
use App\Models\MotivoRechazoCandidato;
use App\Models\PreguntaFiltroCandidato;
use App\Models\Puesto;
use Illuminate\Database\Seeder;

class PreguntasFiltroCandidatoSeeder extends Seeder
{
    /**
     * Preguntas de filtro base (requisitos indispensables al registrar un
     * candidato). Idempotente por clave: si RH ya editó o desactivó la
     * pregunta desde Configuración, un reseed no la pisa (firstOrCreate).
     * Sin Faker: corre también en producción.
     */
    public function run(): void
    {
        $motivo = MotivoRechazoCandidato::query()->where('clave', 'no_cumple_requisito')->value('id');

        // Todo gestor (fijo, volante, grupal) trabaja en campo en moto: es
        // obligatorio. Mismo grupo que usan los indicadores (PuestoJerarquiaSeeder).
        $gestores = Puesto::query()
            ->where(fn ($q) => $q->where('grupo_indicador', GrupoPuestoIndicador::Gestores->value)
                ->orWhereIn('nombre', ['Gestor', 'Gestor Volante', 'Gestor grupal']))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($gestores === []) {
            return;
        }

        PreguntaFiltroCandidato::query()->firstOrCreate(['clave' => 'maneja_moto'], [
            'pregunta' => '¿Sabe manejar moto? (el trabajo de gestor es en moto)',
            'puestos' => $gestores,
            'respuesta_requerida' => true,
            'eliminatoria' => true,
            'motivo_rechazo_id' => $motivo,
            'activo' => true,
            'orden' => 1,
        ]);
    }
}
