<?php

use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh_admin puede registrar un candidato y queda un seguimiento inicial', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), [
            'nombre' => 'Ana',
            'apellidos' => 'García',
            'correo' => 'ana.garcia@example.com',
        ])
        ->assertSessionHasNoErrors();

    $candidato = Candidato::where('correo', 'ana.garcia@example.com')->firstOrFail();

    // store() siembra el pipeline en EstadoCandidato::Recibidos — 'nuevo' no
    // es un valor del enum actual (pipeline de 10 fases + 4 estados de
    // salida, ver App\Enums\EstadoCandidato).
    expect($candidato->estado->value)->toBe('recibidos')
        ->and($candidato->seguimientos()->count())->toBe(1);
});

test('desde el tablero nadie puede avanzar a un candidato: los avances son acciones del workflow', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null]);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    foreach (['entrevista_pendiente', 'autorizacion_rh_pendiente', 'autorizado_rh', 'contratado'] as $estado) {
        $this->actingAs($usuario)
            ->put(route('rh.candidatos.estado', $candidato), ['estado' => $estado])
            ->assertForbidden();
    }

    expect($candidato->fresh()->estado->value)->toBe('recibidos');
});

test('cerrar el proceso desde el tablero exige motivo y queda registrado', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'no_seleccionado'])
        ->assertSessionHasErrors('observaciones');

    $this->actingAs($usuario)
        ->put(route('rh.candidatos.estado', $candidato), ['estado' => 'no_seleccionado', 'nota' => 'Eligió otra vacante.'])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado->value)->toBe('no_seleccionado')
        ->and($candidato->fresh()->motivo_salida)->toBe('Eligió otra vacante.');
});

test('reclutamiento revisa el perfil con la acción del workflow y el candidato pasa a entrevista', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.perfil', $candidato), ['viable' => true])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado->value)->toBe('entrevista_pendiente');

    // Un colaborador sin permisos no puede ejecutar acciones del workflow.
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $this->actingAs($colaborador)
        ->post(route('rh.candidatos.entrevista', $candidato), ['realizada_en' => now()->subHour()->toDateTimeString(), 'resultado' => 'viable'])
        ->assertForbidden();
});

test('un colaborador no puede ver candidatos', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertForbidden();
});

// Regresión: MariaDB reportaba "Column 'candidato_id' in field list is
// ambiguous" (error 1052) al abrir un candidato con altaDigital/
// incorporacionInvitacion (relaciones latestOfMany()) usando el atajo
// "relacion:col1,col2" de eager loading — ver CandidatoController::show().
test('abrir un candidato con altaDigital e incorporacionInvitacion no rompe por columna ambigua', function () {
    $candidato = Candidato::factory()->create();
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.show', $candidato))
        ->assertOk();
});

test('puesto, sucursal y empresa del candidato se derivan de la vacante seleccionada, no se capturan a mano', function () {
    $sucursal = Sucursal::factory()->create();
    $empresa = Empresa::factory()->create();
    $puesto = Puesto::factory()->create();
    $vacante = Vacante::factory()->create([
        'puesto_id' => $puesto->id,
        'sucursal_id' => $sucursal->id,
        'empresa_id' => $empresa->id,
    ]);
    $candidato = Candidato::factory()->create(['vacante_id' => $vacante->id]);

    expect($candidato->puesto_objetivo_id)->toBe($puesto->id)
        ->and($candidato->sucursal_id)->toBe($sucursal->id)
        ->and($candidato->empresa_id)->toBe($empresa->id);

    // Si además mandan valores manuales distintos, la vacante sigue
    // ganando: nunca deben quedar inconsistentes entre sí.
    $otroPuesto = Puesto::factory()->create();
    $otraSucursal = Sucursal::factory()->create();
    $candidato->update([
        'puesto_objetivo_id' => $otroPuesto->id,
        'sucursal_id' => $otraSucursal->id,
        'vacante_id' => $vacante->id,
    ]);

    $candidato->refresh();
    expect($candidato->puesto_objetivo_id)->toBe($puesto->id)
        ->and($candidato->sucursal_id)->toBe($sucursal->id);
});
