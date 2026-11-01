<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\TipoAgendaEvento;
use App\Enums\TipoTarea;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\Vacante;
use App\Services\Notificaciones\DestinoNotificacionService;
use App\Services\Reclutamiento\AgendaEventoService;
use App\Services\Reclutamiento\AprobadorEntrevistaService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\IntervencionCandidatoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->sucursal = Sucursal::factory()->create(['es_corporativo' => false, 'activo' => true]);
    $this->puestos = collect(['Dirección Comercial', 'Gerente Regional', 'Subgerente Regional', 'Gerente de Sucursal', 'Subgerente', 'Gestor', 'Coordinadora de Sucursal', 'Gerente de operaciones'])
        ->mapWithKeys(fn (string $n) => [$n => Puesto::factory()->create(['nombre' => $n, 'activo' => true])]);

    $cuenta = fn (string $puesto, string $rol) => clUsuario($rol, ['puesto_id' => $this->puestos[$puesto]->id, 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo]);
    $this->dc = $cuenta('Dirección Comercial', 'director_comercial');
    $this->gr = $cuenta('Gerente Regional', 'gerente_regional');
    $this->sr = $cuenta('Subgerente Regional', 'gerente_regional');
    $this->gs = $cuenta('Gerente de Sucursal', 'gerente_sucursal');
    $this->rh = clUsuario('rh_admin');

    $this->candidatoA = fn (string $puesto): Candidato => Candidato::factory()->create([
        'vacante_id' => Vacante::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->puestos[$puesto]->id, 'empresa_id' => $this->sucursal->empresa_id])->id,
        'puesto_objetivo_id' => $this->puestos[$puesto]->id,
        'sucursal_id' => $this->sucursal->id,
        'gerente_involucrado_id' => null,
        'estado' => EstadoCandidato::EntrevistaPendiente,
    ]);
    $this->servicio = app(AprobadorEntrevistaService::class);
});

function aeViable(): array
{
    return ['resultado' => 'viable', 'observaciones' => 'Buen perfil.', 'realizada_en' => now()->toDateTimeString()];
}

test('candidato a Gestor: lo aprueba el Gerente de Sucursal, no regionales, RH ni Dirección Comercial', function () {
    $candidato = ($this->candidatoA)('Gestor');

    expect($this->servicio->aprobadores($candidato)->pluck('id')->all())->toBe([$this->gs->id])
        ->and($this->servicio->puedeAprobar($this->gr, $candidato))->toBeFalse()
        ->and($this->servicio->puedeAprobar($this->rh, $candidato))->toBeFalse()
        ->and($this->servicio->puedeAprobar($this->dc, $candidato))->toBeFalse();

    expect(fn () => app(CandidatoWorkflowService::class)->registrarEntrevista($candidato, $this->rh, aeViable()))
        ->toThrow(AuthorizationException::class, AprobadorEntrevistaService::MENSAJE_NO_CORRESPONDE);

    expect(app(CandidatoWorkflowService::class)->registrarEntrevista($candidato, $this->gs, aeViable())->estado)->toBe(EstadoCandidato::PsicometricasPendientes);
});

test('«Gerente de operaciones» aprueba como Gerente de Sucursal', function () {
    $this->gs->colaborador->update(['puesto_id' => $this->puestos['Gerente de operaciones']->id]);

    expect($this->servicio->aprobadores(($this->candidatoA)('Gestor'))->pluck('id')->all())->toBe([$this->gs->id]);
});

test('sin gerente vigente suben los regionales; sin nadie, RH con autorización final y no otro rol', function () {
    $this->gs->colaborador->update(['estatus' => EstadoUsuario::Inactivo]);
    $candidato = ($this->candidatoA)('Gestor');

    expect($this->servicio->aprobadores($candidato)->pluck('id')->sort()->values()->all())->toBe(collect([$this->gr->id, $this->sr->id])->sort()->values()->all());

    $this->gr->colaborador->update(['estatus' => EstadoUsuario::Inactivo]);
    $this->sr->colaborador->update(['estatus' => EstadoUsuario::Inactivo]);

    expect($this->servicio->aprobadores($candidato)->all())->toBe([])
        ->and($this->servicio->puedeAprobar($this->rh, $candidato))->toBeTrue()
        ->and($this->servicio->puedeAprobar($this->dc, $candidato))->toBeFalse();
});

test('un gerente no aprueba a su propio reemplazo ni a su Subgerente, web y API', function (string $puesto) {
    $candidato = ($this->candidatoA)($puesto);

    expect($this->servicio->puedeAprobar($this->gs, $candidato))->toBeFalse()
        ->and(collect(app(CandidatoWorkflowService::class)->accionesPermitidas($candidato, $this->gs))->pluck('clave'))->not->toContain('registrar_entrevista');

    $this->actingAs($this->gs)->post(route('rh.candidatos.entrevista', $candidato), aeViable())->assertForbidden();
    Sanctum::actingAs($this->gs);
    $this->postJson(route('api.v1.rh.candidatos.entrevista', $candidato), aeViable())->assertForbidden();

    expect($candidato->fresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente);
})->with(['Gerente de Sucursal', 'Subgerente']);

test('Subgerente o Gerente de Sucursal: pendiente para ambos regionales y Dirección Comercial; decide uno y se cierra', function (string $puesto, string $quien) {
    $candidato = ($this->candidatoA)('Gestor');
    $candidato->update(['puesto_objetivo_id' => $this->puestos[$puesto]->id, 'vacante_id' => Vacante::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->puestos[$puesto]->id, 'empresa_id' => $this->sucursal->empresa_id])->id, 'estado' => EstadoCandidato::Recibidos]);
    $workflow = app(CandidatoWorkflowService::class);
    $workflow->pasarAEntrevistaPorAgenda($candidato->fresh(), $this->rh);

    $asignados = TareaRh::query()->whereNull('resuelta_en')->where('tipo', TipoTarea::CandidatoEntrevista->value)->pluck('asignado_user_id')->sort()->values()->all();
    expect($asignados)->toBe(collect([$this->dc->id, $this->gr->id, $this->sr->id])->sort()->values()->all());

    $workflow->registrarEntrevista($candidato->fresh(), $this->{$quien}, aeViable());

    expect(TareaRh::query()->whereNull('resuelta_en')->where('tipo', TipoTarea::CandidatoEntrevista->value)->count())->toBe(0);
})->with([
    ['Subgerente', 'sr'], ['Subgerente', 'dc'], ['Gerente de Sucursal', 'gr'], ['Gerente de Sucursal', 'sr'],
]);

test('candidato a puesto regional: solo Dirección Comercial', function () {
    $candidato = ($this->candidatoA)('Subgerente Regional');

    expect($this->servicio->aprobadores($candidato)->pluck('id')->all())->toBe([$this->dc->id])
        ->and($this->servicio->puedeAprobar($this->gr, $candidato))->toBeFalse();
});

test('fuera de la línea (Coordinadora): sin regla nueva, permiso y alcance como hasta hoy', function () {
    $candidato = ($this->candidatoA)('Coordinadora de Sucursal');

    expect($this->servicio->puedeAprobar($this->rh, $candidato))->toBeTrue()
        ->and($this->servicio->puedeAprobar($this->gs, $candidato))->toBeTrue();
});

test('psicométricas y socioeconómico no cambian: el responsable sigue siendo el de siempre', function () {
    $candidato = ($this->candidatoA)('Subgerente');
    $candidato->update(['estado' => EstadoCandidato::RevisionPsicometricas]);

    $claves = collect(app(CandidatoWorkflowService::class)->accionesPermitidas($candidato->fresh(), $this->gs))->pluck('clave');

    expect($claves)->toContain('revisar_psicometricas');
});

test('la cita de un Subgerente o Gerente avisa a sus aprobadores, no a la sucursal; la de un Gestor, a la sucursal', function () {
    $agenda = app(AgendaEventoService::class);
    $evento = fn (Candidato $c) => new AgendaEvento(['tipo' => TipoAgendaEvento::Entrevista, 'sucursal_id' => $this->sucursal->id, 'candidato_id' => $c->id]);

    $reemplazo = $agenda->destinatarios($this->sucursal->id, $evento(($this->candidatoA)('Gerente de Sucursal')))->pluck('id');
    $gestor = $agenda->destinatarios($this->sucursal->id, $evento(($this->candidatoA)('Gestor')))->pluck('id');

    expect($reemplazo)->not->toContain($this->gs->id)->toContain($this->gr->id, $this->sr->id, $this->dc->id)
        ->and($gestor)->toContain($this->gs->id)->not->toContain($this->dc->id);
});

test('Dirección Comercial solo ve Subgerentes y Gerentes: listado web, API y ficha sin revelar datos', function () {
    $gestor = ($this->candidatoA)('Gestor');
    $subgerente = ($this->candidatoA)('Subgerente');
    $regional = ($this->candidatoA)('Gerente Regional');

    $this->actingAs($this->dc)->get(route('rh.candidatos.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('candidatos', fn ($c) => collect($c)->pluck('id')->sort()->values()->all() === collect([$subgerente->id, $regional->id])->sort()->values()->all()));

    Sanctum::actingAs($this->dc);
    expect($this->getJson(route('api.v1.rh.candidatos.index'))->assertOk()->json('data.*.id'))->not->toContain($gestor->id)->toContain($subgerente->id);

    $negada = $this->getJson(route('api.v1.rh.candidatos.show', $gestor))->assertForbidden();
    expect($negada->getContent())->not->toContain($gestor->nombre);

    $this->actingAs($this->dc)->get(route('rh.candidatos.show', $subgerente))->assertOk();
});

test('el aviso de intervención abre la ficha del candidato para Dirección Comercial, y «Pendientes» ya no da 403', function () {
    $candidato = ($this->candidatoA)('Subgerente');
    $candidato->update(['estado' => EstadoCandidato::RechazadoRh]);
    $this->sr->givePermissionTo('candidatos.solicitar_intervencion');
    $intervencion = app(IntervencionCandidatoService::class)->solicitar($candidato->fresh(), $this->sr, 'Vale revisarlo.');

    $aviso = new DatabaseNotification(['id' => (string) Str::uuid(), 'data' => ['type' => 'intervencion_solicitada', 'related_type' => 'IntervencionCandidato', 'resource_id' => $intervencion->id]]);
    $destino = app(DestinoNotificacionService::class)->resolver($aviso, $this->dc);

    expect($destino['url'])->toBe(route('rh.candidatos.show', $candidato, false));

    $this->actingAs($this->dc)->get($destino['url'])->assertOk();
    $this->actingAs($this->dc)->get(route('rh.pendientes.index'))->assertOk();
});

test('intervención por nivel: Gestor a regionales, Subgerente y Gerente a Dirección Comercial', function () {
    $gestor = ($this->candidatoA)('Gestor');
    $gerente = ($this->candidatoA)('Gerente de Sucursal');
    $gestor->update(['estado' => EstadoCandidato::RechazadoRh]);
    $gerente->update(['estado' => EstadoCandidato::RechazadoRh]);
    $this->gs->givePermissionTo('candidatos.solicitar_intervencion');
    $servicio = app(IntervencionCandidatoService::class);

    $deGestor = $servicio->solicitar($gestor->fresh(), $this->gs, 'Motivo.');
    $deGerente = $servicio->solicitar($gerente->fresh(), $this->gs, 'Motivo.');

    expect($servicio->pendienteDecidiblePor($gestor->fresh(), $this->gr)?->id)->toBe($deGestor->id)
        ->and($servicio->pendienteDecidiblePor($gestor->fresh(), $this->dc))->toBeNull()
        ->and($servicio->pendienteDecidiblePor($gerente->fresh(), $this->dc)?->id)->toBe($deGerente->id)
        ->and($servicio->pendienteDecidiblePor($gerente->fresh(), $this->gr))->toBeNull();
});

test('un gerente asignado a mano no se salta la regla', function () {
    $candidato = ($this->candidatoA)('Gestor');
    $otro = clUsuario('colaborador');
    $candidato->update(['gerente_involucrado_id' => $otro->id]);

    expect($this->servicio->aprobadores($candidato->fresh())->pluck('id')->all())->toBe([$this->gs->id]);
});
