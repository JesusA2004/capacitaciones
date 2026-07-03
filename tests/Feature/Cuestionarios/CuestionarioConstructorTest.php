<?php

use App\Models\BancoPregunta;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\Pregunta;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->instructor = User::factory()->create();
    $this->instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'cuestionario']);
    $this->curso = $curso;
    $this->modulo = $modulo;
});

test('se puede configurar un cuestionario para una leccion', function () {
    $this->actingAs($this->instructor)
        ->post(route('cursos.lecciones.cuestionario.store', [$this->curso, $this->modulo, $this->leccion]), [
            'titulo' => 'Evaluación de inducción',
            'calificacion_minima' => 80,
            'intentos_maximos' => 3,
        ])
        ->assertRedirect();

    $cuestionario = Cuestionario::where('leccion_id', $this->leccion->id)->firstOrFail();
    expect($cuestionario->titulo)->toBe('Evaluación de inducción');
    expect($cuestionario->calificacion_minima)->toBe(80);
    expect($cuestionario->intentos_maximos)->toBe(3);
});

test('se pueden asignar preguntas de varios bancos a un cuestionario, respetando el orden enviado', function () {
    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $this->leccion->id]);
    $bancoA = BancoPregunta::factory()->create();
    $bancoB = BancoPregunta::factory()->create();
    $preguntaA = Pregunta::factory()->create(['banco_pregunta_id' => $bancoA->id]);
    $preguntaB = Pregunta::factory()->create(['banco_pregunta_id' => $bancoB->id]);

    $this->actingAs($this->instructor)
        ->put(route('cuestionarios.preguntas.actualizar', $cuestionario), [
            'preguntas' => [
                ['pregunta_id' => $preguntaB->id, 'puntos' => 5],
                ['pregunta_id' => $preguntaA->id],
            ],
        ])
        ->assertRedirect();

    $preguntas = $cuestionario->fresh()->preguntas;

    expect($preguntas)->toHaveCount(2);
    expect($preguntas->first()->id)->toBe($preguntaB->id);
    expect($preguntas->first()->pivot->puntos)->toBe(5);
    expect($preguntas->last()->id)->toBe($preguntaA->id);
});

test('un colaborador no puede configurar cuestionarios', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('cursos.lecciones.cuestionario.edit', [$this->curso, $this->modulo, $this->leccion]))
        ->assertForbidden();
});
