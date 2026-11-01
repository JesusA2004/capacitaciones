<?php

use App\Enums\EstadoAgendaEvento;
use App\Enums\EstadoCandidato;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Reclutamiento\AgendaEventoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Hallazgo 12: `gestionar` solo revisaba la sucursal. Un Gerente de Sucursal cancelaba
| o reprogramaba la cita de la Gerencia Regional y actuaba sobre la de quien aspira a
| su propio puesto.
*/

function rfAgUsuario(string $rol, Sucursal $sucursal, string $puesto): User
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'puesto_id' => Puesto::query()->firstOrCreate(['nombre' => $puesto])->id,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

function rfAgCita(User $actor, ?Candidato $candidato = null): AgendaEvento
{
    return app(AgendaEventoService::class)->agendar([
        'sucursal_id' => test()->sucursal->id,
        'candidato_id' => ($candidato ?? test()->candidato)->id,
        'tipo' => 'entrevista',
        'fecha' => '2026-11-02',
        'hora' => '10:00',
    ], $actor)['evento'];
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-30 16:00:00', 'UTC'));
    $this->sucursal = Sucursal::factory()->create();
    $this->rh = clUsuario('rh_admin');
    $this->regional = rfAgUsuario('gerente_regional', $this->sucursal, 'Gerente Regional');
    $this->gerente = rfAgUsuario('gerente_sucursal', $this->sucursal, 'Gerente de Sucursal');
    $this->subgerente = rfAgUsuario('subgerente', $this->sucursal, 'Subgerente');
    $this->candidato = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    $this->agenda = app(AgendaEventoService::class);
});

afterEach(fn () => Carbon::setTestNow());

test('un gerente no cancela, reprograma ni corrige la cita de un puesto superior', function () {
    $cita = rfAgCita($this->regional);

    expect(fn () => $this->agenda->cancelar($cita, 'Intento de un puesto inferior', $this->gerente))->toThrow(AuthorizationException::class);
    expect(fn () => $this->agenda->reprogramar($cita, '2026-11-03', '11:00', null, $this->gerente))->toThrow(AuthorizationException::class);
    expect(fn () => $this->agenda->corregirNotas($cita, 'Otra nota', 'Intento de un puesto inferior', $this->gerente))->toThrow(AuthorizationException::class);
    expect($cita->refresh()->estado)->toBe(EstadoAgendaEvento::Programado);
});

test('la API también lo niega', function () {
    $cita = rfAgCita($this->regional);
    Sanctum::actingAs($this->gerente);

    $this->postJson("/api/v1/agenda/{$cita->id}/cancelar", ['motivo' => 'Intento de un puesto inferior'])->assertForbidden();
    expect($cita->refresh()->estado)->toBe(EstadoAgendaEvento::Programado);
});

test('un puesto superior y RH sí modifican lo agendado abajo', function () {
    $this->agenda->cancelar(rfAgCita($this->gerente), 'La regional reorganiza la semana', $this->regional);
    $this->candidato->refresh();
    $cita = $this->agenda->cancelar(rfAgCita($this->regional), 'RH reorganiza la semana', $this->rh);

    expect($cita->estado)->toBe(EstadoAgendaEvento::Cancelado);
});

test('quien agendó opera su propia cita, sin importar su rango', function () {
    $cita = rfAgCita($this->gerente);

    expect($this->agenda->corregirNotas($cita, 'Nota mía', 'Corrijo mi propia nota', $this->gerente)->notas)->toBe('Nota mía');
});

test('lo que un superior reprogramó ya no lo cambia quien agendó originalmente', function () {
    $original = rfAgCita($this->gerente);
    $nueva = $this->agenda->reprogramar($original, '2026-11-03', '11:00', 'Cambio de la regional', $this->regional)['evento'];

    expect(fn () => $this->agenda->cancelar($nueva, 'Intento de un puesto inferior', $this->gerente))->toThrow(AuthorizationException::class);
});

test('registrar el resultado es el siguiente paso: no se bloquea por quién agendó', function () {
    $cita = rfAgCita($this->regional);
    Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'UTC'));

    $resultado = $this->agenda->registrarResultadoEntrevista($cita, ['resultado' => 'viable'], $this->gerente);

    expect($resultado->estado)->toBe(EstadoAgendaEvento::Realizado);
});

test('un gerente no gestiona la cita de quien aspira a Subgerente o Gerente', function () {
    $aspirante = Candidato::factory()->create([
        'sucursal_id' => $this->sucursal->id,
        'estado' => EstadoCandidato::EntrevistaPendiente,
        'puesto_objetivo_id' => Puesto::query()->where('nombre', 'Gerente de Sucursal')->value('id'),
    ]);
    $cita = rfAgCita($this->rh, $aspirante);

    expect($this->gerente->can('gestionar', $cita))->toBeFalse();

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/agenda/{$cita->id}/cancelar", ['motivo' => 'Intento sobre un reemplazo'])->assertForbidden();
});
