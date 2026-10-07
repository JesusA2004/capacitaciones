<?php

namespace Database\Seeders;

use App\Models\MotivoRechazoCandidato;
use Illuminate\Database\Seeder;

class MotivosRechazoCandidatoSeeder extends Seeder
{
    /**
     * Catálogo base de motivos de rechazo de candidatos (CLAUDE.md §10).
     * Idempotente (updateOrCreate por clave): RH puede desactivar o agregar
     * motivos nuevos desde Configuración sin que un reseed los duplique o
     * reactive los que ya desactivó.
     */
    public function run(): void
    {
        $motivos = [
            ['clave' => 'no_cumple_perfil', 'nombre' => 'No cumple perfil', 'no_recontratable_por_defecto' => false],
            ['clave' => 'entrevista_no_favorable', 'nombre' => 'Entrevista no favorable', 'no_recontratable_por_defecto' => false],
            ['clave' => 'psicometricos', 'nombre' => 'Psicométricos', 'no_recontratable_por_defecto' => false],
            ['clave' => 'socioeconomico', 'nombre' => 'Socioeconómico', 'no_recontratable_por_defecto' => false],
            ['clave' => 'referencias', 'nombre' => 'Referencias', 'no_recontratable_por_defecto' => false],
            ['clave' => 'vacante_cubierta', 'nombre' => 'Vacante cubierta', 'no_recontratable_por_defecto' => false],
            ['clave' => 'no_respondio', 'nombre' => 'No respondió', 'no_recontratable_por_defecto' => false],
            ['clave' => 'desistio', 'nombre' => 'Desistió', 'no_recontratable_por_defecto' => false],
            ['clave' => 'antecedente_laboral', 'nombre' => 'Antecedente laboral', 'no_recontratable_por_defecto' => true],
            ['clave' => 'conducta', 'nombre' => 'Conducta', 'no_recontratable_por_defecto' => true],
            ['clave' => 'informacion_falsa', 'nombre' => 'Información falsa', 'no_recontratable_por_defecto' => true],
            ['clave' => 'otro', 'nombre' => 'Otro', 'no_recontratable_por_defecto' => false],
        ];

        foreach ($motivos as $motivo) {
            MotivoRechazoCandidato::query()->updateOrCreate(['clave' => $motivo['clave']], [...$motivo, 'activo' => true]);
        }
    }
}
