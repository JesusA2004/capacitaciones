<?php

use App\Enums\EstadoAsignacion;
use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\Departamento;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador_capacitacion');
});

test('se puede asignar un curso a un colaborador especifico', function () {
    $curso = Curso::factory()->create();
    $colaborador = User::factory()->create();

    $this->actingAs($this->admin)->post(route('asignaciones.store'), [
        'nombre' => 'Asignación individual',
        'curso_id' => $curso->id,
        'destinos' => [['tipo' => 'usuario', 'id' => $colaborador->id]],
    ])->assertRedirect(route('asignaciones.index'));

    $asignacion = Asignacion::where('nombre', 'Asignación individual')->first();

    expect($asignacion)->not->toBeNull();
    expect(AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $colaborador->id)->exists())->toBeTrue();
});

test('se puede asignar un curso masivamente por sucursal', function () {
    $curso = Curso::factory()->create();
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $colaboradorA1 = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $colaboradorA2 = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $colaboradorB = User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $this->actingAs($this->admin)->post(route('asignaciones.store'), [
        'nombre' => 'Asignación por sucursal',
        'curso_id' => $curso->id,
        'destinos' => [['tipo' => 'sucursal', 'id' => $sucursalA->id]],
    ])->assertRedirect();

    $asignacion = Asignacion::where('nombre', 'Asignación por sucursal')->first();
    $idsAsignados = AsignacionUsuario::where('asignacion_id', $asignacion->id)->pluck('user_id');

    expect($idsAsignados)->toContain($colaboradorA1->id, $colaboradorA2->id);
    expect($idsAsignados)->not->toContain($colaboradorB->id);
});

test('se puede asignar un curso masivamente a todos los colaboradores', function () {
    $curso = Curso::factory()->create();
    User::factory()->count(3)->create();
    $totalUsuarios = User::count();

    $this->actingAs($this->admin)->post(route('asignaciones.store'), [
        'nombre' => 'Asignación general',
        'curso_id' => $curso->id,
        'destinos' => [['tipo' => 'todos', 'id' => null]],
    ])->assertRedirect();

    $asignacion = Asignacion::where('nombre', 'Asignación general')->first();

    expect(AsignacionUsuario::where('asignacion_id', $asignacion->id)->count())->toBe($totalUsuarios);
});

test('asignar por rol solo incluye a usuarios con ese rol', function () {
    $curso = Curso::factory()->create();
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $rolInstructor = Role::where('name', 'instructor')->first();

    $this->actingAs($this->admin)->post(route('asignaciones.store'), [
        'nombre' => 'Asignación por rol',
        'curso_id' => $curso->id,
        'destinos' => [['tipo' => 'rol', 'id' => $rolInstructor->id]],
    ])->assertRedirect();

    $asignacion = Asignacion::where('nombre', 'Asignación por rol')->first();
    $idsAsignados = AsignacionUsuario::where('asignacion_id', $asignacion->id)->pluck('user_id');

    expect($idsAsignados)->toContain($instructor->id);
    expect($idsAsignados)->not->toContain($colaborador->id);
});

test('materializar una asignacion dos veces no genera duplicados', function () {
    $curso = Curso::factory()->create();
    $colaborador = User::factory()->create();

    app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Prueba idempotencia',
        'responsable_id' => $this->admin->id,
        'obligatoria' => true,
    ], [['tipo' => 'usuario', 'id' => $colaborador->id]]);

    $asignacion = Asignacion::where('nombre', 'Prueba idempotencia')->first();

    // Materializar de nuevo manualmente: no debe crear un segundo registro.
    app(AsignacionService::class)->materializar($asignacion);
    app(AsignacionService::class)->materializar($asignacion);

    expect(AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $colaborador->id)->count())->toBe(1);
});

test('al crear un colaborador nuevo se le aplican las asignaciones vigentes de su sucursal', function () {
    $curso = Curso::factory()->create();
    $sucursal = Sucursal::factory()->create();
    $departamento = Departamento::factory()->create();

    $this->actingAs($this->admin)->post(route('asignaciones.store'), [
        'nombre' => 'Vigente para la sucursal',
        'curso_id' => $curso->id,
        'destinos' => [['tipo' => 'sucursal', 'id' => $sucursal->id]],
    ])->assertRedirect();

    Notification::fake();

    $this->actingAs($this->admin)->post(route('administracion.usuarios.store'), [
        'name' => 'Nuevo',
        'apellidos' => 'Ingreso',
        'email' => 'nuevo.ingreso@mrlana.test',
        'sucursal_principal_id' => $sucursal->id,
        'departamento_id' => $departamento->id,
        'roles' => ['colaborador'],
    ])->assertSessionHasNoErrors();

    $nuevoUsuario = User::where('email', 'nuevo.ingreso@mrlana.test')->first();
    $asignacion = Asignacion::where('nombre', 'Vigente para la sucursal')->first();

    expect(AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $nuevoUsuario->id)->exists())->toBeTrue();
});

test('cancelar una asignacion marca como canceladas las pendientes pero no las completadas', function () {
    $curso = Curso::factory()->create();
    $colaborador1 = User::factory()->create();
    $colaborador2 = User::factory()->create();

    $asignacionService = app(AsignacionService::class);
    $asignacion = $asignacionService->crear($curso, [
        'nombre' => 'Para cancelar',
        'responsable_id' => $this->admin->id,
    ], [
        ['tipo' => 'usuario', 'id' => $colaborador1->id],
        ['tipo' => 'usuario', 'id' => $colaborador2->id],
    ]);

    AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $colaborador1->id)
        ->update(['estado' => EstadoAsignacion::Completada->value, 'completado_en' => now()]);

    $this->actingAs($this->admin)->post(route('asignaciones.cancelar', $asignacion))->assertRedirect();

    $registro1 = AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $colaborador1->id)->first();
    $registro2 = AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $colaborador2->id)->first();

    expect($registro1->estado)->toBe(EstadoAsignacion::Completada);
    expect($registro2->estado)->toBe(EstadoAsignacion::Cancelada);
    expect($asignacion->fresh()->activa)->toBeFalse();
});

test('un colaborador no puede crear asignaciones', function () {
    $curso = Curso::factory()->create();
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->post(route('asignaciones.store'), [
        'nombre' => 'Intento',
        'curso_id' => $curso->id,
        'destinos' => [['tipo' => 'todos', 'id' => null]],
    ])->assertForbidden();
});

test('la vista previa calcula el total sin persistir nada', function () {
    $sucursal = Sucursal::factory()->create();
    User::factory()->count(2)->create(['sucursal_principal_id' => $sucursal->id]);

    $respuesta = $this->actingAs($this->admin)->postJson(route('asignaciones.previsualizar'), [
        'destinos' => [['tipo' => 'sucursal', 'id' => $sucursal->id]],
    ]);

    $respuesta->assertOk()->assertJson(['total' => 2]);
    expect(Asignacion::count())->toBe(0);
});
