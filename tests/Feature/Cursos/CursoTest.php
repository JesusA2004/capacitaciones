<?php

use App\Models\Curso;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function usuarioConRolCurso(string $rol): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

test('un administrador de capacitacion puede crear un curso', function () {
    $usuario = usuarioConRolCurso('administrador_capacitacion');

    $respuesta = $this->actingAs($usuario)->post(route('cursos.store'), [
        'titulo' => 'Curso de prueba',
        'descripcion' => 'Descripción de prueba',
    ]);

    $curso = Curso::where('titulo', 'Curso de prueba')->first();

    expect($curso)->not->toBeNull();
    expect($curso->estado->value)->toBe('borrador');
    $respuesta->assertRedirect(route('cursos.edit', $curso));
});

test('un colaborador no puede crear cursos', function () {
    $usuario = usuarioConRolCurso('colaborador');

    $this->actingAs($usuario)
        ->post(route('cursos.store'), ['titulo' => 'Intento'])
        ->assertForbidden();
});

test('solo quien tiene el permiso cursos.publicar puede publicar un curso', function () {
    $curso = Curso::factory()->create();
    $instructor = usuarioConRolCurso('instructor');

    $this->actingAs($instructor)
        ->post(route('cursos.publicar', $curso))
        ->assertForbidden();

    expect($curso->fresh()->estado->value)->toBe('borrador');

    $admin = usuarioConRolCurso('administrador_capacitacion');

    $this->actingAs($admin)
        ->post(route('cursos.publicar', $curso))
        ->assertRedirect();

    expect($curso->fresh()->estado->value)->toBe('publicado');
    expect($curso->fresh()->publicado_en)->not->toBeNull();
});

test('se pueden asignar cursos requisito previo a un curso', function () {
    $cursoBase = Curso::factory()->create();
    $cursoRequisito = Curso::factory()->create();
    $admin = usuarioConRolCurso('administrador_capacitacion');

    $this->actingAs($admin)
        ->put(route('cursos.update', $cursoBase), [
            'titulo' => $cursoBase->titulo,
            'requisitos_previos' => [$cursoRequisito->id],
        ])
        ->assertSessionHasNoErrors();

    expect($cursoBase->fresh()->requisitosPrevios->pluck('id')->all())->toBe([$cursoRequisito->id]);
});
