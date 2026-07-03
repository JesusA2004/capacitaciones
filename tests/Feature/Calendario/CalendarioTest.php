<?php

use App\Enums\EstadoAsistencia;
use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\Asistencia;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('el calendario muestra la fecha limite de una asignacion propia dentro del mes', function () {
    $colaborador = User::factory()->create();
    $admin = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso con fecha límite', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $admin->id]);

    $fechaLimite = now()->addDays(5);
    AsignacionUsuario::create([
        'asignacion_id' => $asignacion->id,
        'user_id' => $colaborador->id,
        'estado' => 'pendiente',
        'fecha_limite' => $fechaLimite,
    ]);

    $respuesta = $this->actingAs($colaborador)
        ->get(route('calendario', ['anio' => $fechaLimite->year, 'mes' => $fechaLimite->month]))
        ->assertOk();

    $respuesta->assertInertia(function ($page) {
        $eventos = collect($page->toArray()['props']['eventos']);

        expect($eventos->firstWhere('tipo', 'fecha_limite'))->not->toBeNull();
    });
});

test('el calendario no muestra la fecha limite de otro colaborador', function () {
    $colaborador = User::factory()->create();
    $otroColaborador = User::factory()->create();
    $admin = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso ajeno', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $admin->id]);

    $fechaLimite = now()->addDays(5);
    AsignacionUsuario::create([
        'asignacion_id' => $asignacion->id,
        'user_id' => $otroColaborador->id,
        'estado' => 'pendiente',
        'fecha_limite' => $fechaLimite,
    ]);

    $respuesta = $this->actingAs($colaborador)
        ->get(route('calendario', ['anio' => $fechaLimite->year, 'mes' => $fechaLimite->month]))
        ->assertOk();

    $respuesta->assertInertia(fn ($page) => expect($page->toArray()['props']['eventos'])->toBeEmpty());
});

test('un instructor ve en el calendario las sesiones que el mismo programo', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);

    $fechaSesion = now()->addDays(3);
    $sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $leccion->id,
        'fecha_inicio' => $fechaSesion,
        'creado_por' => $instructor->id,
    ]);

    $respuesta = $this->actingAs($instructor)
        ->get(route('calendario', ['anio' => $fechaSesion->year, 'mes' => $fechaSesion->month]))
        ->assertOk();

    $respuesta->assertInertia(function ($page) use ($sesion) {
        $eventos = collect($page->toArray()['props']['eventos']);

        expect($eventos->firstWhere('id', "sesion_{$sesion->id}"))->not->toBeNull();
    });
});

test('un colaborador ve en el calendario la sesion a la que tiene asistencia registrada', function () {
    $colaborador = User::factory()->create();
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);

    $fechaSesion = now()->addDays(2);
    $sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $leccion->id,
        'fecha_inicio' => $fechaSesion,
        'creado_por' => User::factory()->create()->id,
    ]);
    Asistencia::create(['sesion_en_vivo_id' => $sesion->id, 'user_id' => $colaborador->id, 'estado' => EstadoAsistencia::Pendiente->value]);

    $respuesta = $this->actingAs($colaborador)
        ->get(route('calendario', ['anio' => $fechaSesion->year, 'mes' => $fechaSesion->month]))
        ->assertOk();

    $respuesta->assertInertia(function ($page) use ($sesion) {
        $eventos = collect($page->toArray()['props']['eventos']);

        expect($eventos->firstWhere('id', "sesion_{$sesion->id}"))->not->toBeNull();
    });
});
