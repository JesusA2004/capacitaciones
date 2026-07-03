<?php

namespace Database\Seeders;

use App\Enums\EstadoCurso;
use App\Enums\TipoLeccion;
use App\Models\Curso;
use Illuminate\Database\Seeder;

class CursoInduccionSeeder extends Seeder
{
    public function run(): void
    {
        $curso = Curso::firstOrCreate(
            ['titulo' => 'Curso de Inducción General'],
            [
                'descripcion' => 'Curso introductorio para todo colaborador que se integra a Mr. Lana.',
                'objetivo' => 'Conocer la historia, valores, políticas y herramientas básicas de la empresa.',
                'duracion_estimada_minutos' => 90,
                'estado' => EstadoCurso::Publicado,
                'calificacion_minima' => 80,
                'intentos_maximos' => 3,
                'requiere_orden' => true,
                'genera_constancia' => true,
                'alcance_global' => true,
                'publicado_en' => now(),
            ],
        );

        if ($curso->modulos()->exists()) {
            return;
        }

        $bienvenida = $curso->modulos()->create([
            'titulo' => 'Bienvenida a Mr. Lana',
            'descripcion' => 'Historia, misión, visión y valores de la empresa.',
            'orden' => 1,
        ]);

        $bienvenida->lecciones()->create([
            'titulo' => '¿Quiénes somos?',
            'tipo' => TipoLeccion::Texto,
            'contenido' => 'Mr. Lana es una empresa dedicada a ofrecer soluciones financieras accesibles y confiables en todo México.',
            'obligatoria' => true,
            'orden' => 1,
            'duracion_estimada_minutos' => 5,
        ]);

        $bienvenida->lecciones()->create([
            'titulo' => 'Video de bienvenida (recurso de prueba)',
            'tipo' => TipoLeccion::Video,
            'contenido' => 'Video simulado de bienvenida institucional. El procesamiento real a HLS se implementa en la Fase 3 (biblioteca multimedia).',
            'obligatoria' => true,
            'orden' => 2,
            'duracion_estimada_minutos' => 10,
        ]);

        $politicas = $curso->modulos()->create([
            'titulo' => 'Políticas internas',
            'descripcion' => 'Reglamento interno y políticas de conducta.',
            'orden' => 2,
        ]);

        $politicas->lecciones()->create([
            'titulo' => 'Reglamento interno de trabajo',
            'tipo' => TipoLeccion::Documento,
            'contenido' => 'Documento simulado del reglamento interno. La carga real de documentos se implementa en la Fase 3.',
            'obligatoria' => true,
            'orden' => 1,
            'duracion_estimada_minutos' => 15,
        ]);

        $politicas->lecciones()->create([
            'titulo' => 'Confirmo haber leído el reglamento',
            'tipo' => TipoLeccion::Confirmacion,
            'contenido' => 'Al continuar confirmas que leíste y aceptas el reglamento interno de trabajo.',
            'obligatoria' => true,
            'orden' => 2,
            'duracion_estimada_minutos' => 2,
        ]);
    }
}
