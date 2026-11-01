<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Enums\EstadoUsuario;
use App\Enums\TipoReapertura;
use App\Enums\TipoSolicitudInterna;
use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\IntervencionCandidato;
use App\Models\Puesto;
use App\Models\Reapertura;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Reaperturas\ReaperturaService;
use App\Services\Reclutamiento\AprobadorEntrevistaService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Regla de la dueña del negocio: se reabre solo a uno o más niveles por encima de
| quien decidió. Mismo nivel, inferior, RH y uno mismo: 403. Web y API por igual.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->sucursal = Sucursal::factory()->create(['es_corporativo' => false, 'activo' => true]);
    $this->puestos = collect(['Dirección General', 'Dirección Comercial', 'Gerente Regional', 'Subgerente Regional', 'Gerente de Sucursal', 'Subgerente', 'Gestor'])
        ->mapWithKeys(fn (string $n) => [$n => Puesto::factory()->create(['nombre' => $n, 'activo' => true])]);

    $cuenta = fn (string $puesto, string $rol, ?Sucursal $sucursal = null): User => clUsuario($rol, [
        'puesto_id' => $this->puestos[$puesto]->id, 'sucursal_principal_id' => ($sucursal ?? $this->sucursal)->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2020-01-15',
    ]);

    $this->dg = $cuenta('Dirección General', 'direccion');
    $this->dc = $cuenta('Dirección Comercial', 'director_comercial');
    $this->gr = $cuenta('Gerente Regional', 'gerente_regional');
    $this->sr = $cuenta('Subgerente Regional', 'gerente_regional');
    $this->gs = $cuenta('Gerente de Sucursal', 'gerente_sucursal');
    $this->otroGs = $cuenta('Gerente de Sucursal', 'gerente_sucursal');
    $this->ss = $cuenta('Subgerente', 'subgerente');
    $this->gestor = $cuenta('Gestor', 'colaborador');
    $this->rh = clUsuario('rh_admin');

    $this->solicitud = function (array $atributos = []): SolicitudInterna {
        $fabrica = SolicitudInterna::factory()->create([
            'user_id' => $this->gestor->id, 'colaborador_id' => $this->gestor->colaborador_id,
            'tipo' => TipoSolicitudInterna::Permiso, 'estado' => EstadoSolicitudInterna::Enviada, ...$atributos,
        ]);

        return $fabrica;
    };
    $this->visto = fn (SolicitudInterna $s, User $autor, string $decision = SolicitudAprobacion::DECISION_APROBADO) => SolicitudAprobacion::query()->create([
        'solicitud_interna_id' => $s->id, 'nivel' => SolicitudAprobacion::NIVEL_JEFE_INMEDIATO, 'decision' => $decision, 'user_id' => $autor->id, 'comentario' => 'Decisión previa confidencial',
    ]);
    $this->candidatoA = fn (string $puesto, array $atributos = []): Candidato => Candidato::factory()->create([
        'vacante_id' => Vacante::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->puestos[$puesto]->id, 'empresa_id' => $this->sucursal->empresa_id])->id,
        'puesto_objetivo_id' => $this->puestos[$puesto]->id, 'sucursal_id' => $this->sucursal->id,
        'gerente_involucrado_id' => null, 'estado' => EstadoCandidato::EntrevistaPendiente, ...$atributos,
    ]);
    $this->entrevista = ['resultado' => 'viable', 'observaciones' => 'Buen perfil.', 'realizada_en' => now()->toDateTimeString()];
    $this->servicio = app(ReaperturaService::class);
});

function reUrl(TipoReapertura $tipo, int $id): string
{
    return route('rh.reaperturas.store', ['tipo' => $tipo->value, 'id' => $id]);
}

function reUrlApi(TipoReapertura $tipo, int $id): string
{
    return route('api.v1.rh.reaperturas.store', ['tipo' => $tipo->value, 'id' => $id]);
}

test('dos niveles arriba reabre un visto bueno: motivo, historial, decisión anterior y vuelve a trámite', function () {
    $solicitud = ($this->solicitud)(['estado' => EstadoSolicitudInterna::EnRevision]);
    ($this->visto)($solicitud, $this->gs);

    $this->actingAs($this->gr)->post(reUrl(TipoReapertura::VistoBuenoSolicitud, $solicitud->id), ['motivo' => 'Falta validar la ruta.'])->assertRedirect()->assertSessionHasNoErrors();

    $reapertura = Reapertura::query()->firstOrFail();

    expect($solicitud->fresh()->estado)->toBe(EstadoSolicitudInterna::Enviada)
        ->and(SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->count())->toBe(0)
        ->and($reapertura->decidido_por)->toBe($this->gs->id)
        ->and($reapertura->reabierto_por)->toBe($this->gr->id)
        ->and($reapertura->motivo)->toBe('Falta validar la ruta.')
        ->and($reapertura->decision_anterior['visto_bueno']['comentario'])->toBe('Decisión previa confidencial')
        ->and($solicitud->historial()->where('accion', 'decision_reabierta')->value('comentario'))->toBe('Falta validar la ruta.');
});

test('tres niveles arriba también reabre', function () {
    $solicitud = ($this->solicitud)();
    ($this->visto)($solicitud, $this->ss);

    Sanctum::actingAs($this->gr);
    $this->postJson(reUrlApi(TipoReapertura::VistoBuenoSolicitud, $solicitud->id), ['motivo' => 'Revisión de la gerencia regional.'])->assertCreated();

    expect(Reapertura::query()->where('decidido_por', $this->ss->id)->where('reabierto_por', $this->gr->id)->exists())->toBeTrue();
});

test('inferior, mismo nivel, uno mismo y RH reciben 403 sin revelar datos, en web y API', function (string $actor, string $autor) {
    $solicitud = ($this->solicitud)(['estado' => EstadoSolicitudInterna::EnRevision, 'motivo' => 'Motivo privado del solicitante']);
    ($this->visto)($solicitud, $this->{$autor});

    $this->actingAs($this->{$actor})->post(reUrl(TipoReapertura::VistoBuenoSolicitud, $solicitud->id), ['motivo' => 'Quiero reabrirla.'])->assertForbidden();

    Sanctum::actingAs($this->{$actor});
    $respuesta = $this->postJson(reUrlApi(TipoReapertura::VistoBuenoSolicitud, $solicitud->id), ['motivo' => 'Quiero reabrirla.'])->assertForbidden();

    expect($respuesta->json('message'))->toBe(ReaperturaService::MENSAJE_SIN_AUTORIDAD)
        ->and($respuesta->getContent())->not->toContain('confidencial')->not->toContain('privado')->not->toContain($this->{$autor}->name);

    expect(Reapertura::query()->count())->toBe(0)
        ->and($solicitud->fresh()->estado)->toBe(EstadoSolicitudInterna::EnRevision);
})->with([
    'inferior sobre superior' => ['gs', 'sr'],
    'subgerente regional sobre gerente regional' => ['sr', 'gr'],
    'mismo nivel' => ['otroGs', 'gs'],
    'uno mismo' => ['gs', 'gs'],
    'RH sobre la línea' => ['rh', 'gs'],
]);

test('el motivo es obligatorio', function () {
    $solicitud = ($this->solicitud)();
    ($this->visto)($solicitud, $this->gs);

    Sanctum::actingAs($this->gr);
    $this->postJson(reUrlApi(TipoReapertura::VistoBuenoSolicitud, $solicitud->id), ['motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');

    expect(Reapertura::query()->count())->toBe(0);
});

test('un rechazo por visto bueno regresa a trámite y queda el registro de quién reabrió', function () {
    $solicitud = ($this->solicitud)(['estado' => EstadoSolicitudInterna::Rechazada, 'revisado_por' => $this->gs->id, 'revisado_en' => now(), 'motivo_rechazo' => 'No procede']);
    ($this->visto)($solicitud, $this->gs, SolicitudAprobacion::DECISION_RECHAZADO);

    $this->servicio->reabrir(TipoReapertura::VistoBuenoSolicitud, $solicitud->id, $this->sr, 'Sí procede, lo reviso yo.');

    $fresca = $solicitud->fresh();

    expect($fresca->estado)->toBe(EstadoSolicitudInterna::Enviada)
        ->and($fresca->motivo_rechazo)->toBeNull()
        ->and($fresca->revisado_por)->toBeNull()
        ->and(app(AprobacionJerarquicaService::class)->reabrioUnVistoBueno($this->sr, $fresca))->toBeTrue()
        ->and(app(AprobacionJerarquicaService::class)->reabrioUnVistoBueno($this->gr, $fresca))->toBeFalse();
});

test('solicitud rechazada: la línea reabre lo de un inferior; lo que rechazó RH, solo Dirección General', function () {
    $porGerente = ($this->solicitud)(['estado' => EstadoSolicitudInterna::Rechazada, 'revisado_por' => $this->gs->id, 'motivo_rechazo' => 'No']);
    $porRh = ($this->solicitud)(['estado' => EstadoSolicitudInterna::Rechazada, 'revisado_por' => $this->rh->id, 'motivo_rechazo' => 'No']);

    $this->servicio->reabrir(TipoReapertura::SolicitudRechazada, $porGerente->id, $this->gr, 'Se revisa de nuevo.');
    expect($porGerente->fresh()->estado)->not->toBe(EstadoSolicitudInterna::Rechazada);

    expect(fn () => $this->servicio->reabrir(TipoReapertura::SolicitudRechazada, $porRh->id, $this->gr, 'Se revisa de nuevo.'))
        ->toThrow(AuthorizationException::class);

    $this->servicio->reabrir(TipoReapertura::SolicitudRechazada, $porRh->id, $this->dg, 'Decisión de dirección.');
    expect($porRh->fresh()->estado)->not->toBe(EstadoSolicitudInterna::Rechazada);
});

test('una baja nunca se reabre por este flujo; no le decimos el estado a quien no tiene autoridad', function () {
    $baja = ($this->solicitud)(['tipo' => TipoSolicitudInterna::BajaColaborador, 'estado' => EstadoSolicitudInterna::Rechazada, 'revisado_por' => $this->gs->id, 'motivo_rechazo' => 'No']);

    Sanctum::actingAs($this->gr);
    $this->postJson(reUrlApi(TipoReapertura::SolicitudRechazada, $baja->id), ['motivo' => 'Quiero reabrirla.'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.motivo.0', 'Las bajas las cancela o reabre solo Recursos Humanos.');

    expect($this->servicio->disponibles($this->gr, $baja))->toBe([]);
});

test('vacaciones rechazadas: el superior reabre y vuelven a pendiente', function () {
    $vacaciones = SolicitudVacaciones::factory()->create([
        'user_id' => $this->gestor->id, 'colaborador_id' => $this->gestor->colaborador_id,
        'estado' => EstadoSolicitudVacaciones::Rechazada, 'revisado_por' => $this->gs->id, 'motivo_rechazo' => 'Sin cobertura',
    ]);

    Sanctum::actingAs($this->otroGs);
    $this->postJson(reUrlApi(TipoReapertura::VacacionesRechazadas, $vacaciones->id), ['motivo' => 'Quiero reabrirlas.'])->assertForbidden();

    Sanctum::actingAs($this->sr);
    $this->postJson(reUrlApi(TipoReapertura::VacacionesRechazadas, $vacaciones->id), ['motivo' => 'Ya hay cobertura.'])->assertCreated();

    expect($vacaciones->fresh()->estado)->toBe(EstadoSolicitudVacaciones::Pendiente)
        ->and($vacaciones->fresh()->motivo_rechazo)->toBeNull()
        ->and(Reapertura::query()->firstOrFail()->decision_anterior['motivo_rechazo'])->toBe('Sin cobertura');
});

test('aprobación de entrevista: el regional reabre lo del gerente, y puede volver a decidir; el gerente no', function () {
    $candidato = ($this->candidatoA)('Gestor');
    $workflow = app(CandidatoWorkflowService::class);
    $workflow->registrarEntrevista($candidato, $this->gs, $this->entrevista);

    $this->actingAs($this->gs)->post(reUrl(TipoReapertura::EntrevistaCandidato, $candidato->id), ['motivo' => 'Me equivoqué.'])->assertForbidden();

    $this->actingAs($this->sr)->post(reUrl(TipoReapertura::EntrevistaCandidato, $candidato->id), ['motivo' => 'Faltó validar referencias.'])->assertRedirect()->assertSessionHasNoErrors();

    $fresco = $candidato->fresh();

    expect($fresco->estado)->toBe(EstadoCandidato::EntrevistaPendiente)
        ->and(CandidatoEntrevista::query()->where('candidato_id', $candidato->id)->count())->toBe(1)
        ->and(app(AprobadorEntrevistaService::class)->puedeAprobar($this->sr, $fresco))->toBeTrue()
        ->and(app(AprobadorEntrevistaService::class)->puedeAprobar($this->gr, $fresco))->toBeFalse()
        ->and($fresco->seguimientos()->where('nota', 'like', '%Faltó validar referencias%')->exists())->toBeTrue();

    expect($workflow->registrarEntrevista($fresco, $this->sr, $this->entrevista)->estado)->toBe(EstadoCandidato::PsicometricasPendientes);
});

test('una entrevista No viable también se reabre y limpia la salida', function () {
    $candidato = ($this->candidatoA)('Gestor');
    app(CandidatoWorkflowService::class)->registrarEntrevista($candidato, $this->gs, ['resultado' => 'no_viable', 'observaciones' => 'No cumple.', 'realizada_en' => now()->toDateTimeString()]);
    expect($candidato->fresh()->estado)->toBe(EstadoCandidato::NoViable);

    $this->servicio->reabrir(TipoReapertura::EntrevistaCandidato, $candidato->id, $this->gr, 'Sí cumple.');

    $fresco = $candidato->fresh();
    expect($fresco->estado)->toBe(EstadoCandidato::EntrevistaPendiente)->and($fresco->salida_por)->toBeNull()->and($fresco->motivo_salida)->toBeNull();
});

test('Dirección Comercial reabre lo de un regional sobre un Gerente; no alcanza a los gestores', function () {
    $gerente = ($this->candidatoA)('Gerente de Sucursal');
    $workflow = app(CandidatoWorkflowService::class);
    $workflow->registrarEntrevista($gerente, $this->gr, $this->entrevista);

    expect($this->servicio->disponibles($this->sr, $gerente->fresh()))->toBe([]);
    $this->servicio->reabrir(TipoReapertura::EntrevistaCandidato, $gerente->id, $this->dc, 'Revisión de dirección.');
    expect($gerente->fresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente);

    $gestor = ($this->candidatoA)('Gestor');
    $workflow->registrarEntrevista($gestor, $this->gs, $this->entrevista);

    expect(fn () => $this->servicio->reabrir(TipoReapertura::EntrevistaCandidato, $gestor->id, $this->dc, 'Revisión de dirección.'))
        ->toThrow(AuthorizationException::class);
});

test('intervención aprobada: el Gerente Regional reabre lo del Subgerente Regional y el candidato regresa al rechazo; al revés, 403', function () {
    $candidato = ($this->candidatoA)('Gestor', ['estado' => EstadoCandidato::AutorizadoRh]);
    $intervencion = IntervencionCandidato::query()->create([
        'candidato_id' => $candidato->id, 'ruta' => 'regional', 'rechazo_rh_por' => $this->rh->id, 'rechazo_rh_motivo' => 'No', 'rechazo_rh_en' => now(),
        'gerente_solicitante_id' => $this->gs->id, 'motivo_solicitud' => 'Revisar', 'solicitada_en' => now(),
        'estado' => EstadoIntervencionCandidato::Aprobada, 'aprobador_id' => $this->sr->id, 'comentario_decision' => 'Sí', 'decidida_en' => now(),
    ]);

    expect(fn () => $this->servicio->reabrir(TipoReapertura::IntervencionCandidato, $intervencion->id, $this->gs, 'Quiero reabrirla.'))
        ->toThrow(AuthorizationException::class);

    expect(collect($this->servicio->disponibles($this->gr, $candidato))->pluck('tipo')->all())->toContain(TipoReapertura::IntervencionCandidato->value);

    $this->actingAs($this->gr)->post(reUrl(TipoReapertura::IntervencionCandidato, $intervencion->id), ['motivo' => 'No se justificó.'])->assertRedirect()->assertSessionHasNoErrors();

    expect($intervencion->fresh()->estado)->toBe(EstadoIntervencionCandidato::Pendiente)
        ->and($intervencion->fresh()->aprobador_id)->toBeNull()
        ->and($candidato->fresh()->estado)->toBe(EstadoCandidato::RechazadoRh);
});

test('intervención cuyo candidato ya entró a contratación: mensaje claro, sin cambios', function () {
    $candidato = ($this->candidatoA)('Gestor', ['estado' => EstadoCandidato::EnContratacion]);
    $intervencion = IntervencionCandidato::query()->create([
        'candidato_id' => $candidato->id, 'ruta' => 'regional', 'gerente_solicitante_id' => $this->gs->id, 'motivo_solicitud' => 'Revisar', 'solicitada_en' => now(),
        'estado' => EstadoIntervencionCandidato::Aprobada, 'aprobador_id' => $this->sr->id, 'decidida_en' => now(),
    ]);

    Sanctum::actingAs($this->gr);
    $this->postJson(reUrlApi(TipoReapertura::IntervencionCandidato, $intervencion->id), ['motivo' => 'Quiero reabrirla.'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.motivo.0', 'El candidato ya entró a contratación: esta intervención ya no se puede reabrir.');
});

test('el botón solo llega a quien corresponde: pantalla web y ficha de la API', function () {
    $solicitud = ($this->solicitud)(['estado' => EstadoSolicitudInterna::EnRevision]);
    ($this->visto)($solicitud, $this->gs);

    $this->actingAs($this->gr)->get(route('rh.solicitudes.show', $solicitud))->assertOk()
        ->assertInertia(fn ($page) => $page->where('reaperturas.0.tipo', TipoReapertura::VistoBuenoSolicitud->value));
    $this->actingAs($this->otroGs)->get(route('rh.solicitudes.show', $solicitud))->assertOk()
        ->assertInertia(fn ($page) => $page->where('reaperturas', []));

    $candidato = ($this->candidatoA)('Gestor');
    app(CandidatoWorkflowService::class)->registrarEntrevista($candidato, $this->gs, $this->entrevista);

    Sanctum::actingAs($this->sr);
    $this->getJson(route('api.v1.rh.candidatos.show', $candidato))->assertOk()->assertJsonPath('data.reaperturas.0.tipo', TipoReapertura::EntrevistaCandidato->value);
    Sanctum::actingAs($this->otroGs);
    $this->getJson(route('api.v1.rh.candidatos.show', $candidato))->assertOk()->assertJsonPath('data.reaperturas', []);
});
