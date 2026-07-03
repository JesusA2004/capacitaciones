<?php

use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador_capacitacion');
});

test('se pueden agregar modulos y lecciones a un curso, quedando ordenados', function () {
    $curso = Curso::factory()->create();

    $this->actingAs($this->admin)->post(route('cursos.modulos.store', $curso), ['titulo' => 'Módulo 1'])->assertRedirect();
    $this->actingAs($this->admin)->post(route('cursos.modulos.store', $curso), ['titulo' => 'Módulo 2'])->assertRedirect();

    $modulos = $curso->modulos()->orderBy('orden')->get();

    expect($modulos)->toHaveCount(2);
    expect($modulos[0]->titulo)->toBe('Módulo 1');
    expect($modulos[0]->orden)->toBe(1);
    expect($modulos[1]->orden)->toBe(2);

    $modulo = $modulos[0];

    $this->actingAs($this->admin)->post(route('cursos.lecciones.store', [$curso, $modulo]), [
        'titulo' => 'Lección A',
        'tipo' => 'texto',
        'contenido' => 'Contenido A',
        'obligatoria' => true,
    ])->assertRedirect();

    $this->actingAs($this->admin)->post(route('cursos.lecciones.store', [$curso, $modulo]), [
        'titulo' => 'Lección B',
        'tipo' => 'enlace',
        'url' => 'https://ejemplo.com',
        'obligatoria' => true,
    ])->assertRedirect();

    $lecciones = $modulo->lecciones()->orderBy('orden')->get();

    expect($lecciones)->toHaveCount(2);
    expect($lecciones[0]->titulo)->toBe('Lección A');
    expect($lecciones[1]->orden)->toBe(2);
});

test('una leccion de tipo enlace requiere una url valida', function () {
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);

    $this->actingAs($this->admin)
        ->post(route('cursos.lecciones.store', [$curso, $modulo]), [
            'titulo' => 'Lección sin url',
            'tipo' => 'enlace',
        ])
        ->assertSessionHasErrors('url');
});

test('mover un modulo hacia abajo intercambia su orden con el siguiente', function () {
    $curso = Curso::factory()->create();
    $moduloA = CursoModulo::factory()->create(['curso_id' => $curso->id, 'orden' => 1]);
    $moduloB = CursoModulo::factory()->create(['curso_id' => $curso->id, 'orden' => 2]);

    $this->actingAs($this->admin)
        ->post(route('cursos.modulos.mover', [$curso, $moduloA]), ['direccion' => 'abajo'])
        ->assertRedirect();

    expect($moduloA->fresh()->orden)->toBe(2);
    expect($moduloB->fresh()->orden)->toBe(1);
});

test('eliminar un modulo elimina tambien sus lecciones', function () {
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id]);

    $this->actingAs($this->admin)
        ->delete(route('cursos.modulos.destroy', [$curso, $modulo]))
        ->assertRedirect();

    expect(CursoModulo::find($modulo->id))->toBeNull();
    expect(Leccion::find($leccion->id))->toBeNull();
});

test('un colaborador no puede modificar modulos de un curso', function () {
    $curso = Curso::factory()->create();
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('cursos.modulos.store', $curso), ['titulo' => 'Intento'])
        ->assertForbidden();
});
