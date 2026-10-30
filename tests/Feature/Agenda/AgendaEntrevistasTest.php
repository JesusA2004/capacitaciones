<?php

use App\Enums\EstadoAgendaEvento;
use App\Enums\EstadoCandidato;
use App\Enums\OrigenAgendaEvento;
use App\Enums\ResultadoAgendaEvento;
use App\Enums\ResultadoEtapaCandidato;
use App\Enums\TipoEvidenciaCandidato;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoSocioeconomico;
use App\Models\Colaborador;
use App\Models\NotificacionEntrega;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Reclutamiento\AgendaConsultaService;
use App\Services\Reclutamiento\AgendaEventoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
 * Agenda de entrevistas (Etapa 1, sección 9; escenarios 43-50): la cita es una
 * capa sobre el recorrido del candidato, que sigue en CandidatoWorkflowService.
 */

function agUsuario(string $rol, ?Sucursal $sucursal = null, ?string $puesto = null): User
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal?->id,
        'estatus' => 'activo',
        'puesto_id' => $puesto !== null ? Puesto::query()->firstOrCreate(['nombre' => $puesto])->id : null,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

function agDatos(array $extra = []): array
{
    return [
        'sucursal_id' => test()->ixtlahuaca->id,
        'candidato_id' => test()->candidato->id,
        'tipo' => 'entrevista',
        'fecha' => '2026-11-02',
        'hora' => '10:00',
        ...$extra,
    ];
}

function agEvento(array $extra = [], ?User $actor = null): AgendaEvento
{
    return app(AgendaEventoService::class)->agendar(agDatos($extra), $actor ?? test()->rh)['evento'];
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Storage::fake((string) config('reclutamiento.disk'));
    Carbon::setTestNow(Carbon::parse('2026-10-30 16:00:00', 'UTC'));

    $this->ixtlahuaca = Sucursal::factory()->create(['nombre' => 'Ixtlahuaca']);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula']);
    $this->rh = agUsuario('rh_admin');
    $this->gerente = agUsuario('gerente', $this->ixtlahuaca, 'Gerente de Sucursal');
    $this->subgerente = agUsuario('subgerente', $this->ixtlahuaca, 'Subgerente');
    $this->gerenteTula = agUsuario('gerente', $this->tula, 'Gerente de Sucursal');
    $this->candidato = Candidato::factory()->create([
        'sucursal_id' => $this->ixtlahuaca->id,
        'estado' => EstadoCandidato::EntrevistaPendiente,
        'telefono' => '7221234567',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

test('un evento en Ixtlahuaca llega a gerente y subgerente, y lo ven en su agenda (43, 44)', function () {
    $evento = agEvento();

    expect($evento->estado)->toBe(EstadoAgendaEvento::Programado)
        ->and($evento->origen)->toBe(OrigenAgendaEvento::Reclutamiento)
        ->and($evento->agendado_por)->toBe($this->rh->id);

    foreach ([$this->gerente, $this->subgerente] as $usuario) {
        expect(NotificacionEntrega::query()->where('user_id', $usuario->id)->where('evento', 'agenda_evento_agendado')->count())->toBe(1);

        Sanctum::actingAs($usuario);
        $this->getJson('/api/v1/agenda')->assertOk()->assertJsonPath('data.dias.0.eventos.0.id', $evento->id);
    }

    expect(NotificacionEntrega::query()->where('user_id', $this->gerenteTula->id)->count())->toBe(0);
});

test('el origen sale del alcance de quien agenda, no de la pantalla', function () {
    $evento = agEvento([], $this->gerente);

    expect($evento->origen)->toBe(OrigenAgendaEvento::Gerente)
        ->and(NotificacionEntrega::query()->where('user_id', $this->gerente->id)->count())->toBe(0)
        ->and(NotificacionEntrega::query()->where('user_id', $this->subgerente->id)->count())->toBe(1);
});

test('el alcance se aplica en backend: otra sucursal no ve ni opera el evento', function () {
    $evento = agEvento();

    Sanctum::actingAs($this->gerenteTula);
    $this->getJson('/api/v1/agenda')->assertOk()->assertJsonPath('data.dias', []);
    $this->getJson("/api/v1/agenda/{$evento->id}")->assertForbidden();
    $this->postJson("/api/v1/agenda/{$evento->id}/cancelar", ['motivo' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'viable'])->assertForbidden();
    $this->postJson('/api/v1/agenda', agDatos())->assertForbidden();
});

test('el subgerente ve el evento pero no captura ni recibe datos del candidato', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'UTC'));
    $evento = agEvento(['fecha' => '2026-11-03']);
    Carbon::setTestNow(Carbon::parse('2026-11-03 18:00:00', 'UTC'));

    Sanctum::actingAs($this->subgerente);
    $this->getJson("/api/v1/agenda/{$evento->id}")->assertOk()
        ->assertJsonPath('data.candidato.telefono', null)
        ->assertJsonPath('data.candidato.tiene_cv', null)
        ->assertJsonPath('data.domicilio', null)
        ->assertJsonPath('data.puede.registrar_resultado', false);
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'viable'])->assertForbidden();
    $this->getJson("/api/v1/agenda/{$evento->id}/cv")->assertForbidden();

    Sanctum::actingAs($this->gerente);
    $this->getJson("/api/v1/agenda/{$evento->id}")->assertOk()->assertJsonPath('data.candidato.telefono', '7221234567');
});

test('reprogramar no duplica avisos, conserva el historial y cuenta una sola cita (45)', function () {
    $evento = agEvento();
    $servicio = app(AgendaEventoService::class);

    $nueva = $servicio->reprogramar($evento, '2026-11-04', '11:00', 'Pidió cambio', $this->rh)['evento'];
    $repetida = $servicio->reprogramar($evento, '2026-11-04', '11:00', 'Pidió cambio', $this->rh)['evento'];

    expect($repetida->id)->toBe($nueva->id)
        ->and($evento->refresh()->estado)->toBe(EstadoAgendaEvento::Reprogramado)
        ->and($evento->reprogramado_a_id)->toBe($nueva->id)
        ->and($evento->historial()->where('accion', 'reprogramado')->count())->toBe(1)
        ->and(NotificacionEntrega::query()->where('user_id', $this->gerente->id)->where('evento', 'agenda_evento_reprogramado')->count())->toBe(1);

    $indicadores = app(AgendaConsultaService::class)->indicadores($this->rh, ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
    expect($indicadores['agendadas'])->toBe(1);
});

test('los recordatorios son idempotentes y la cita movida o cancelada ya no se recuerda', function () {
    $movida = agEvento();
    app(AgendaEventoService::class)->reprogramar($movida, '2026-11-20', '10:00', null, $this->rh);

    $otro = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    $vigente = agEvento(['candidato_id' => $otro->id, 'hora' => '12:00']);
    $tercero = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    $cancelada = agEvento(['candidato_id' => $tercero->id, 'hora' => '13:00']);
    app(AgendaEventoService::class)->cancelar($cancelada, 'Ya no aplica', $this->rh);

    Carbon::setTestNow(Carbon::parse('2026-11-01 15:00:00', 'UTC'));
    $this->artisan('agenda:enviar-recordatorios')->assertSuccessful();
    $this->artisan('agenda:enviar-recordatorios')->assertSuccessful();

    $recordatorios = NotificacionEntrega::query()->where('user_id', $this->gerente->id)->where('evento', 'agenda_recordatorio');
    expect((clone $recordatorios)->count())->toBe(1)
        ->and((clone $recordatorios)->value('related_id'))->toBe($vigente->id);

    Carbon::setTestNow(Carbon::parse('2026-11-02 15:00:00', 'UTC'));
    $this->artisan('agenda:enviar-recordatorios')->assertSuccessful();
    expect(NotificacionEntrega::query()->where('user_id', $this->subgerente->id)->where('evento', 'agenda_recordatorio')->count())->toBe(2);
});

test('un fallo al notificar no deshace la cita', function () {
    $mock = Mockery::mock(NotificacionEventosService::class);
    $mock->shouldReceive('crearPendiente')->andThrow(new RuntimeException('push caído'));
    app()->instance(NotificacionEventosService::class, $mock);

    expect(agEvento()->exists)->toBeTrue();
});

test('Viable avanza al candidato por el workflow y entra al récord (46)', function () {
    $evento = agEvento([], $this->gerente);
    Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'UTC'));

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'viable'])->assertOk()
        ->assertJsonPath('data.estado', 'realizado');
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'viable'])->assertOk();

    expect($this->candidato->refresh()->estado)->toBe(EstadoCandidato::PsicometricasPendientes)
        ->and(CandidatoEntrevista::query()->where('candidato_id', $this->candidato->id)->count())->toBe(1)
        ->and(CandidatoEntrevista::query()->where('candidato_id', $this->candidato->id)->value('resultado'))->toBe(ResultadoEtapaCandidato::Viable);

    $record = app(AgendaConsultaService::class)->recordPorSucursal($this->rh, ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
    expect($record[0]['sucursal'])->toBe('Ixtlahuaca')
        ->and($record[0]['realizadas'])->toBe(1)
        ->and($record[0]['viables'])->toBe(1)
        ->and($record[0]['pct_viabilidad'])->toBe(100.0)
        ->and($record[0]['pct_reclutamiento'])->toBe(0.0);

    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'no_viable', 'motivo' => 'perfil', 'comentario' => 'x'])->assertUnprocessable();
});

test('No viable conserva motivo y comentario, y cierra al candidato (47)', function () {
    $evento = agEvento();
    Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'UTC'));

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'no_viable'])->assertUnprocessable();
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'no_viable', 'motivo' => 'inventado', 'comentario' => 'x'])->assertUnprocessable();
    $this->postJson("/api/v1/agenda/{$evento->id}/resultado-entrevista", ['resultado' => 'no_viable', 'motivo' => 'actitud', 'comentario' => 'Llegó tarde y sin interés'])->assertOk();

    expect($evento->refresh()->motivo_no_viable?->value)->toBe('actitud')
        ->and($evento->comentario_resultado)->toBe('Llegó tarde y sin interés')
        ->and($this->candidato->refresh()->estado)->toBe(EstadoCandidato::NoViable)
        ->and($this->candidato->motivo_salida)->toContain('Actitud');
});

test('No asistió afecta la inasistencia, deja reprogramar y no cambia al candidato (48)', function () {
    $evento = agEvento();
    Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'UTC'));
    $servicio = app(AgendaEventoService::class);

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/agenda/{$evento->id}/no-asistio", ['comentario' => 'No llegó'])->assertOk()->assertJsonPath('data.puede.reprogramar', true);

    expect($this->candidato->refresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente);

    $nueva = $servicio->reprogramar($evento, '2026-11-05', '10:00', null, $this->gerente)['evento'];

    expect($evento->refresh()->estado)->toBe(EstadoAgendaEvento::NoAsistio)
        ->and($nueva->reprogramado_de_id)->toBe($evento->id);

    $indicadores = app(AgendaConsultaService::class)->indicadores($this->rh, ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
    expect($indicadores['no_asistio'])->toBe(1)
        ->and($indicadores['agendadas'])->toBe(2)
        ->and($indicadores['pct_inasistencia'])->toBe(100.0);
});

test('el socioeconómico guarda resultado y evidencia en el expediente del candidato (49)', function () {
    $this->candidato->update(['estado' => EstadoCandidato::SocioeconomicoPendiente]);
    $evento = agEvento(['tipo' => 'socioeconomico', 'domicilio' => 'Calle 1, Ixtlahuaca']);
    Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'UTC'));

    Sanctum::actingAs($this->gerente);
    $this->post("/api/v1/agenda/{$evento->id}/resultado-socioeconomico", ['resultado' => 'favorable'], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/api/v1/agenda/{$evento->id}/resultado-socioeconomico", [
        'resultado' => 'favorable',
        'comentario' => 'Vivienda verificada',
        'evidencias' => [UploadedFile::fake()->create('formato.pdf', 100, 'application/pdf'), UploadedFile::fake()->image('casa.jpg')],
    ], ['Accept' => 'application/json'])->assertOk();

    $estudio = CandidatoSocioeconomico::query()->where('candidato_id', $this->candidato->id)->firstOrFail();

    expect($evento->refresh()->resultado)->toBe(ResultadoAgendaEvento::Favorable)
        ->and($estudio->direccion)->toBe('Calle 1, Ixtlahuaca')
        ->and($estudio->resultado)->toBe(ResultadoEtapaCandidato::Viable)
        ->and($estudio->evidencias()->count())->toBe(2)
        ->and($this->candidato->refresh()->estado)->toBe(EstadoCandidato::ReferenciasPendientes);
});

test('sin el permiso de revisar, el estudio queda pendiente de quien sí lo tiene', function () {
    $this->candidato->update(['estado' => EstadoCandidato::SocioeconomicoPendiente]);
    $usuario = agUsuario('subgerente', $this->ixtlahuaca, 'Subgerente');
    $usuario->givePermissionTo('agenda.registrar_resultado', 'candidatos.registrar_socioeconomico', 'candidatos.ver');
    $evento = agEvento(['tipo' => 'socioeconomico', 'domicilio' => 'Calle 1']);
    Carbon::setTestNow(Carbon::parse('2026-11-02 17:00:00', 'UTC'));

    app(AgendaEventoService::class)->registrarResultadoSocioeconomico($evento, ['resultado' => 'favorable'], [UploadedFile::fake()->image('casa.jpg')], $usuario);

    expect($evento->refresh()->estado)->toBe(EstadoAgendaEvento::Realizado)
        ->and($this->candidato->refresh()->estado)->toBe(EstadoCandidato::SocioeconomicoPendiente)
        ->and(CandidatoSocioeconomico::query()->where('candidato_id', $this->candidato->id)->value('resultado'))->toBeNull();
});

test('los indicadores salen de eventos reales y sin datos no hay porcentaje (50)', function () {
    $vacio = app(AgendaConsultaService::class)->indicadores($this->rh, ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
    expect($vacio['agendadas'])->toBe(0)->and($vacio['pct_inasistencia'])->toBeNull()->and($vacio['pct_viabilidad'])->toBeNull();

    $viable = agEvento();
    $otro = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    $falta = agEvento(['candidato_id' => $otro->id, 'hora' => '12:00'], $this->gerente);
    $tercero = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    $cancelada = agEvento(['candidato_id' => $tercero->id, 'hora' => '13:00']);
    $servicio = app(AgendaEventoService::class);
    $servicio->cancelar($cancelada, 'Desistió', $this->rh);

    Carbon::setTestNow(Carbon::parse('2026-11-03 17:00:00', 'UTC'));
    $servicio->registrarResultadoEntrevista($viable, ['resultado' => 'viable'], $this->gerente);
    $servicio->registrarInasistencia($falta, null, $this->gerente);

    $datos = app(AgendaConsultaService::class)->indicadores($this->rh, ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);

    expect($datos['agendadas'])->toBe(2)
        ->and($datos['realizadas'])->toBe(1)
        ->and($datos['no_asistio'])->toBe(1)
        ->and($datos['pct_inasistencia'])->toBe(50.0)
        ->and($datos['pct_viabilidad'])->toBe(100.0)
        ->and($datos['pct_reclutamiento'])->toBe(50.0)
        ->and($datos['dias_cita_resultado'])->toBe(1.0);
});

test('doble envío con la misma clave crea una sola cita, y no hay dos vivas para un candidato', function () {
    Sanctum::actingAs($this->rh);
    $primera = $this->postJson('/api/v1/agenda', agDatos(['clave_idempotencia' => 'abc-123']))->assertCreated();
    $this->postJson('/api/v1/agenda', agDatos(['clave_idempotencia' => 'abc-123']))->assertOk()
        ->assertJsonPath('repetido', true)
        ->assertJsonPath('data.id', $primera->json('data.id'));

    $this->postJson('/api/v1/agenda', agDatos(['hora' => '15:00']))->assertUnprocessable()->assertJsonValidationErrors('candidato_id');

    expect(AgendaEvento::query()->count())->toBe(1)
        ->and(NotificacionEntrega::query()->where('user_id', $this->gerente->id)->count())->toBe(1);
});

test('agendar a un candidato recibido lo pasa a entrevista; uno cerrado o más avanzado se rechaza', function () {
    $this->candidato->update(['estado' => EstadoCandidato::Recibidos]);
    agEvento([], $this->gerente);
    expect($this->candidato->refresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente);

    $cerrado = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::NoViable]);
    expect(fn () => agEvento(['candidato_id' => $cerrado->id]))->toThrow(ValidationException::class);

    $avanzado = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::ReferenciasPendientes]);
    expect(fn () => agEvento(['candidato_id' => $avanzado->id, 'tipo' => 'socioeconomico']))->toThrow(ValidationException::class);

    $deOtra = Candidato::factory()->create(['sucursal_id' => $this->tula->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    expect(fn () => agEvento(['candidato_id' => $deOtra->id]))->toThrow(ValidationException::class);
});

test('el alta rápida no duplica candidatos', function () {
    $nuevo = ['nombre' => 'Ricardo', 'apellidos' => 'Núñez Torres', 'telefono' => '722 999 0000'];
    $evento = app(AgendaEventoService::class)->agendar([...agDatos(), 'candidato_id' => null, 'candidato_nuevo' => $nuevo], $this->rh)['evento'];

    expect($evento->candidato->nombreCompleto())->toContain('Ricardo');

    $repetido = Candidato::query()->count();
    expect(fn () => app(AgendaEventoService::class)->agendar([...agDatos(['hora' => '12:00']), 'candidato_id' => null, 'candidato_nuevo' => ['nombre' => 'Otro', 'telefono' => '+52 722 999 0000']], $this->rh))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AgendaEventoService::class)->agendar([...agDatos(['hora' => '12:00']), 'candidato_id' => null, 'candidato_nuevo' => ['nombre' => 'ricardo', 'apellidos' => 'núñez torres']], $this->rh))
        ->toThrow(ValidationException::class);
    expect(Candidato::query()->count())->toBe($repetido);
});

test('un empalme se advierte sin bloquear; una fecha pasada se rechaza', function () {
    agEvento();
    $otro = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    $resultado = app(AgendaEventoService::class)->agendar(agDatos(['candidato_id' => $otro->id, 'hora' => '10:30']), $this->rh);

    expect($resultado['evento']->exists)->toBeTrue()
        ->and($resultado['empalmes'][0])->toContain('Ixtlahuaca')->toContain('10:00');

    $tercero = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id, 'estado' => EstadoCandidato::EntrevistaPendiente]);
    expect(fn () => agEvento(['candidato_id' => $tercero->id, 'fecha' => '2026-10-01']))->toThrow(ValidationException::class);
});

test('no se captura el resultado antes del día de la cita', function () {
    $evento = agEvento();

    expect(fn () => app(AgendaEventoService::class)->registrarResultadoEntrevista($evento, ['resultado' => 'viable'], $this->gerente))
        ->toThrow(ValidationException::class);
    expect($this->candidato->refresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente);
});

test('la pantalla web respeta alcance y permisos, y las exportaciones son solo con permiso', function () {
    $evento = agEvento();

    $this->actingAs($this->gerente)->get('/reclutamiento/agenda?fecha=2026-11-02')->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('Reclutamiento/Agenda/Index')->has('eventos', 1));
    $this->actingAs($this->gerenteTula)->get('/reclutamiento/agenda?fecha=2026-11-02')->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->has('eventos', 0));
    $this->actingAs($this->gerenteTula)->post("/reclutamiento/agenda/{$evento->id}/cancelar", ['motivo' => 'x'])->assertForbidden();

    $this->actingAs($this->gerente)->get('/reclutamiento/agenda/exportar/excel')->assertForbidden();
    $this->actingAs($this->rh)->get('/reclutamiento/agenda/exportar/excel?fecha=2026-11-02')->assertOk();
    $this->actingAs($this->rh)->get('/reclutamiento/agenda/exportar/pdf?fecha=2026-11-02&vista=record')->assertOk();

    $colaborador = agUsuario('colaborador', $this->ixtlahuaca);
    $this->actingAs($colaborador)->get('/reclutamiento/agenda')->assertForbidden();
    Sanctum::actingAs($colaborador);
    $this->getJson('/api/v1/agenda')->assertForbidden();
});

test('las opciones para agendar respetan el alcance y el permiso', function () {
    $this->actingAs($this->gerente);
    $this->getJson('/api/v1/agenda/opciones')
        ->assertOk()
        ->assertJsonCount(1, 'data.sucursales')
        ->assertJsonPath('data.sucursales.0.id', $this->ixtlahuaca->id)
        ->assertJsonPath('data.candidatos.0.id', $this->candidato->id);

    $this->actingAs($this->gerenteTula);
    $this->getJson('/api/v1/agenda/opciones')->assertOk()->assertJsonPath('data.candidatos', []);

    $this->actingAs($this->subgerente);
    $this->getJson('/api/v1/agenda/opciones')->assertOk()->assertJsonPath('data.candidatos', []);
});

test('CV y evidencia del candidato se descargan por la API solo con alcance', function () {
    $disco = (string) config('reclutamiento.disk');
    Storage::disk($disco)->put('candidatos/cv.pdf', '%PDF-1.4 cv');
    Storage::disk($disco)->put('candidatos/foto.jpg', 'jpg');
    $this->candidato->forceFill(['cv_disk' => $disco, 'cv_path' => 'candidatos/cv.pdf', 'cv_original_name' => 'cv.pdf'])->save();
    $evidencia = CandidatoEvidencia::query()->create([
        'candidato_id' => $this->candidato->id,
        'evidenciable_type' => Candidato::class,
        'evidenciable_id' => $this->candidato->id,
        'tipo' => TipoEvidenciaCandidato::Fotografia,
        'disk' => $disco,
        'path' => 'candidatos/foto.jpg',
        'original_name' => 'foto.jpg',
        'mime' => 'image/jpeg',
        'size' => 3,
    ]);
    $otro = Candidato::factory()->create(['sucursal_id' => $this->ixtlahuaca->id]);

    $this->actingAs($this->gerente);
    $this->get("/api/v1/rh/candidatos/{$this->candidato->id}/cv")->assertOk();
    $this->get("/api/v1/rh/candidatos/{$this->candidato->id}/evidencias/{$evidencia->id}")->assertOk();
    $this->getJson("/api/v1/rh/candidatos/{$otro->id}/evidencias/{$evidencia->id}")->assertNotFound();

    $this->actingAs($this->gerenteTula);
    $this->getJson("/api/v1/rh/candidatos/{$this->candidato->id}/cv")->assertForbidden();
    $this->getJson("/api/v1/rh/candidatos/{$this->candidato->id}/evidencias/{$evidencia->id}")->assertForbidden();
});
