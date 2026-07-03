<?php

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoProgreso;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use App\Services\Capacitacion\ProgresoService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');
});

function crearCursoSecuencial(bool $requiereOrden = true): Curso
{
    $curso = Curso::factory()->create(['requiere_orden' => $requiereOrden]);
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id, 'orden' => 1]);

    Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'orden' => 1, 'titulo' => 'Lección 1', 'obligatoria' => true]);
    Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'orden' => 2, 'titulo' => 'Lección 2', 'obligatoria' => true]);

    return $curso->fresh(['modulos.lecciones']);
}

test('una leccion obligatoria posterior queda bloqueada hasta completar la anterior', function () {
    $curso = crearCursoSecuencial();
    $primera = $curso->modulos->first()->lecciones[0];
    $segunda = $curso->modulos->first()->lecciones[1];

    $servicio = app(ProgresoService::class);

    $estado = $servicio->estadoBloqueoLeccion($this->colaborador, $segunda);
    expect($estado['bloqueada'])->toBeTrue();

    $servicio->completarLeccion($this->colaborador, $primera);

    $estado = $servicio->estadoBloqueoLeccion($this->colaborador, $segunda);
    expect($estado['bloqueada'])->toBeFalse();
});

test('no se puede completar una leccion bloqueada', function () {
    $curso = crearCursoSecuencial();
    $segunda = $curso->modulos->first()->lecciones[1];

    app(ProgresoService::class)->completarLeccion($this->colaborador, $segunda);
})->throws(RuntimeException::class);

test('cuando el curso no requiere orden, las lecciones no se bloquean entre si', function () {
    $curso = crearCursoSecuencial(requiereOrden: false);
    $segunda = $curso->modulos->first()->lecciones[1];

    $estado = app(ProgresoService::class)->estadoBloqueoLeccion($this->colaborador, $segunda);

    expect($estado['bloqueada'])->toBeFalse();
});

test('completar todas las lecciones obligatorias marca el curso e inscripcion como completados', function () {
    $curso = crearCursoSecuencial();
    [$primera, $segunda] = $curso->modulos->first()->lecciones;

    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $asignacion = app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Curso secuencial',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $this->colaborador->id]]);

    $servicio = app(ProgresoService::class);
    $servicio->completarLeccion($this->colaborador, $primera);
    $servicio->completarLeccion($this->colaborador, $segunda);

    $inscripcion = InscripcionCurso::where('user_id', $this->colaborador->id)->where('curso_id', $curso->id)->first();
    expect($inscripcion->estado)->toBe(EstadoProgreso::Completada);
    expect($inscripcion->completado_en)->not->toBeNull();

    $asignacionUsuario = AsignacionUsuario::where('asignacion_id', $asignacion->id)->where('user_id', $this->colaborador->id)->first();
    expect($asignacionUsuario->estado)->toBe(EstadoAsignacion::Completada);
});

test('un colaborador no puede ver el detalle de un curso que no tiene asignado', function () {
    $curso = crearCursoSecuencial();

    $this->actingAs($this->colaborador)
        ->get(route('mi-capacitacion.show', $curso))
        ->assertNotFound();
});

test('un colaborador puede ver y completar las lecciones de un curso asignado', function () {
    $curso = crearCursoSecuencial();
    $primera = $curso->modulos->first()->lecciones[0];

    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Curso visible',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $this->colaborador->id]]);

    $this->actingAs($this->colaborador)
        ->get(route('mi-capacitacion.show', $curso))
        ->assertOk();

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.completar', $primera))
        ->assertRedirect();

    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $primera))->toBeTrue();
});
