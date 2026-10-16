<?php

namespace Database\Seeders;

use App\Enums\CategoriaMotivoRechazo;
use App\Models\MotivoRechazoCandidato;
use Illuminate\Database\Seeder;

class MotivosRechazoCandidatoSeeder extends Seeder
{
    /**
     * Catálogo base de motivos de rechazo de candidatos (CLAUDE.md §10).
     * Idempotente (updateOrCreate por clave): RH puede desactivar o agregar
     * motivos nuevos desde Configuración sin que un reseed los duplique o
     * reactive los que ya desactivó.
     *
     * Cada motivo lleva su categoría (CategoriaMotivoRechazo) para que el
     * reporte de campañas responda «¿por qué ya no continuó?» agrupado:
     * requisito, empresa, candidato o sin contacto.
     */
    public function run(): void
    {
        $requisito = CategoriaMotivoRechazo::Requisito->value;
        $empresa = CategoriaMotivoRechazo::Empresa->value;
        $candidato = CategoriaMotivoRechazo::Candidato->value;
        $sinContacto = CategoriaMotivoRechazo::SinContacto->value;

        $motivos = [
            ['clave' => 'no_cumple_requisito', 'nombre' => 'No cumple un requisito indispensable (p. ej. no maneja moto)', 'categoria' => $requisito, 'no_recontratable_por_defecto' => false],
            ['clave' => 'documentacion_incompleta', 'nombre' => 'Documentación incompleta', 'categoria' => $requisito, 'no_recontratable_por_defecto' => false],
            ['clave' => 'no_cumple_perfil', 'nombre' => 'No cumple perfil', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
            ['clave' => 'entrevista_no_favorable', 'nombre' => 'Entrevista no favorable', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
            ['clave' => 'psicometricos', 'nombre' => 'Psicométricos', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
            ['clave' => 'socioeconomico', 'nombre' => 'Socioeconómico', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
            ['clave' => 'referencias', 'nombre' => 'Referencias', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
            ['clave' => 'vacante_cubierta', 'nombre' => 'Vacante cubierta', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
            ['clave' => 'antecedente_laboral', 'nombre' => 'Antecedente laboral', 'categoria' => $empresa, 'no_recontratable_por_defecto' => true],
            ['clave' => 'conducta', 'nombre' => 'Conducta', 'categoria' => $empresa, 'no_recontratable_por_defecto' => true],
            ['clave' => 'informacion_falsa', 'nombre' => 'Información falsa', 'categoria' => $empresa, 'no_recontratable_por_defecto' => true],
            ['clave' => 'desistio', 'nombre' => 'Desistió', 'categoria' => $candidato, 'no_recontratable_por_defecto' => false],
            ['clave' => 'sueldo_no_le_convence', 'nombre' => 'No le convenció el sueldo o las prestaciones', 'categoria' => $candidato, 'no_recontratable_por_defecto' => false],
            ['clave' => 'horario_o_distancia', 'nombre' => 'Horario o distancia', 'categoria' => $candidato, 'no_recontratable_por_defecto' => false],
            ['clave' => 'acepto_otra_oferta', 'nombre' => 'Aceptó otra oferta', 'categoria' => $candidato, 'no_recontratable_por_defecto' => false],
            ['clave' => 'no_respondio', 'nombre' => 'No respondió', 'categoria' => $sinContacto, 'no_recontratable_por_defecto' => false],
            ['clave' => 'no_se_presento', 'nombre' => 'No se presentó a la cita', 'categoria' => $sinContacto, 'no_recontratable_por_defecto' => false],
            ['clave' => 'otro', 'nombre' => 'Otro', 'categoria' => $empresa, 'no_recontratable_por_defecto' => false],
        ];

        // Sin tocar `activo`: un motivo nuevo nace activo (default de la
        // columna) y uno que RH desactivó se queda desactivado al re-sembrar.
        foreach ($motivos as $motivo) {
            MotivoRechazoCandidato::query()->updateOrCreate(['clave' => $motivo['clave']], $motivo);
        }
    }
}
