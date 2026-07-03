<?php

use App\Models\BancoPregunta;
use App\Models\Pregunta;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->instructor = User::factory()->create();
    $this->instructor->assignRole('instructor');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');
});

test('un colaborador no puede administrar el banco de preguntas', function () {
    $this->actingAs($this->colaborador)
        ->get(route('bancos-preguntas.index'))
        ->assertForbidden();
});

test('un instructor puede crear un banco de preguntas', function () {
    $this->actingAs($this->instructor)
        ->post(route('bancos-preguntas.store'), ['nombre' => 'Seguridad e higiene'])
        ->assertRedirect();

    expect(BancoPregunta::where('nombre', 'Seguridad e higiene')->exists())->toBeTrue();
});

test('crear una pregunta de opcion unica requiere exactamente una opcion correcta', function () {
    $banco = BancoPregunta::factory()->create();

    $this->actingAs($this->instructor)
        ->post(route('bancos-preguntas.preguntas.store', $banco), [
            'enunciado' => '¿Cuál es el color correcto?',
            'tipo' => 'opcion_unica',
            'puntos' => 1,
            'opciones' => [
                ['texto' => 'Verde', 'es_correcta' => true],
                ['texto' => 'Azul', 'es_correcta' => true],
            ],
        ])
        ->assertSessionHasErrors('opciones');

    $this->actingAs($this->instructor)
        ->post(route('bancos-preguntas.preguntas.store', $banco), [
            'enunciado' => '¿Cuál es el color correcto?',
            'tipo' => 'opcion_unica',
            'puntos' => 1,
            'opciones' => [
                ['texto' => 'Verde', 'es_correcta' => true],
                ['texto' => 'Azul', 'es_correcta' => false],
            ],
        ])
        ->assertRedirect();

    $pregunta = Pregunta::firstOrFail();
    expect($pregunta->opciones)->toHaveCount(2);
    expect($pregunta->opciones->where('es_correcta', true))->toHaveCount(1);
});

test('crear una pregunta de respuesta corta no requiere opciones', function () {
    $banco = BancoPregunta::factory()->create();

    $this->actingAs($this->instructor)
        ->post(route('bancos-preguntas.preguntas.store', $banco), [
            'enunciado' => 'Describe el procedimiento de emergencia.',
            'tipo' => 'respuesta_corta',
            'puntos' => 2,
        ])
        ->assertRedirect();

    $pregunta = Pregunta::firstOrFail();
    expect($pregunta->opciones)->toHaveCount(0);
});

test('actualizar una pregunta reemplaza por completo sus opciones', function () {
    $banco = BancoPregunta::factory()->create();
    $pregunta = Pregunta::factory()->create(['banco_pregunta_id' => $banco->id, 'tipo' => 'opcion_unica']);
    $pregunta->opciones()->createMany([
        ['texto' => 'Original A', 'es_correcta' => true, 'orden' => 0],
        ['texto' => 'Original B', 'es_correcta' => false, 'orden' => 1],
    ]);

    $this->actingAs($this->instructor)
        ->put(route('bancos-preguntas.preguntas.update', [$banco, $pregunta]), [
            'enunciado' => $pregunta->enunciado,
            'tipo' => 'opcion_unica',
            'puntos' => 1,
            'opciones' => [
                ['texto' => 'Nueva A', 'es_correcta' => false],
                ['texto' => 'Nueva B', 'es_correcta' => true],
            ],
        ])
        ->assertRedirect();

    $pregunta->refresh()->load('opciones');
    expect($pregunta->opciones)->toHaveCount(2);
    expect($pregunta->opciones->pluck('texto')->all())->toBe(['Nueva A', 'Nueva B']);
});
