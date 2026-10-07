<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\EstadoVacante;
use App\Enums\GrupoPuestoIndicador;
use App\Enums\RutaIntervencionCandidato;
use App\Enums\TipoNodoComercial;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\IntervencionCandidato;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\IntervencionCandidatoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

/**
 * Candidato que recorre TODO el pipeline real con el workflow hasta que RH
 * lo rechaza en la autorización final — nunca se coloca el estado a mano.
 */
function icCandidatoRechazadoPorRh(object $t): Candidato
{
    $estructura = $t->estructura;
    $vacante = Vacante::factory()->create([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estado' => EstadoVacante::Abierta->value,
        'plazas_requeridas' => 1,
        'plazas_disponibles' => 1,
        'plazas_cubiertas' => 0,
        'fecha_apertura' => now()->subDays(5)->toDateString(),
    ]);

    $wf = app(CandidatoWorkflowService::class);
    $c = $wf->registrar([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_objetivo_id' => $estructura['puesto']->id,
        'vacante_id' => $vacante->id,
        'nombre' => 'Marcos',
        'apellidos' => 'Trujillo',
        'correo' => 'marcos.trujillo@mrlana.test',
        'gerente_involucrado_id' => $t->gerente->id,
    ], $t->rh);

    $wf->evaluarPerfil($c, $t->rh, true, null);
    $wf->registrarEntrevista($c, $t->gerente, ['realizada_en' => now()->subDay()->toDateTimeString(), 'resultado' => 'viable']);
    $wf->registrarResultadosPsicometricas($c, $t->rh, 'En perfil.');
    $wf->revisarPsicometricas($c, $t->gerente, true, null);
    $wf->registrarSocioeconomico($c, $t->gerente, ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable']);
    $wf->registrarReferencia($c, $t->rh, ['empresa' => 'X', 'contacto' => 'Y', 'resultado' => 'positiva']);
    $wf->concluirReferencias($c, $t->rh, true, null);
    $wf->preautorizar($c, $t->gerente, null);
    $wf->rechazarRh($c, $t->rh, 'No cumple el perfil solicitado.');

    return $c->refresh();
}

/**
 * Colaborador activo con el puesto "Dirección Comercial" (config
 * ciclo_laboral.organizacion.puestos_direccion_comercial).
 */
function icDireccionComercial(): User
{
    $puesto = Puesto::factory()->create(['nombre' => 'Dirección Comercial']);
    $colaborador = Colaborador::factory()->create(['puesto_id' => $puesto->id, 'estatus' => 'activo']);

    return User::factory()->create(['colaborador_id' => $colaborador->id]);
}

/**
 * Colaborador activo como Gerente Regional de la región de $sucursalId
 * (config organigrama.puestos_de_region + NodoComercial Matriz→Región→Zona).
 */
function icRegionalDe(int $sucursalId): User
{
    $regional = Puesto::factory()->create(['nombre' => 'Gerente regional']);

    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ IC']);
    $region = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región IC', 'parent_id' => $matriz->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'ZONA IC', 'parent_id' => $region->id, 'sucursal_id' => $sucursalId]);

    $colaborador = Colaborador::factory()->create(['puesto_id' => $regional->id, 'sucursal_principal_id' => $sucursalId, 'estatus' => 'activo']);

    return User::factory()->create(['colaborador_id' => $colaborador->id]);
}

test('RH rechaza normalmente: no se crea ninguna intervención hasta que el gerente la solicite', function () {
    $candidato = icCandidatoRechazadoPorRh($this);

    expect($candidato->estado)->toBe(EstadoCandidato::RechazadoRh)
        ->and(IntervencionCandidato::where('candidato_id', $candidato->id)->count())->toBe(0);
});

test('el gerente que entrevistó puede solicitar intervención y solo entonces se notifica a quien decide', function () {
    $this->estructura['puesto']->update(['grupo_indicador' => GrupoPuestoIndicador::Gestores]);
    $candidato = icCandidatoRechazadoPorRh($this);
    $regional = icRegionalDe($this->estructura['sucursal']->id);
    $regional->assignRole('gerente_regional');

    // El pipeline hasta el rechazo ya disparó su propia notificación normal
    // (preautorización); lo que importa aquí es que SOLICITAR la intervención
    // es lo único que puede notificar a Regional/Dirección Comercial.
    Notification::fake();

    $this->actingAs($this->gerente)
        ->post(route('rh.candidatos.intervencion.solicitar', $candidato), ['motivo' => 'Buen desempeño en entrevista, vale la pena reconsiderar.'])
        ->assertSessionHasNoErrors();

    $intervencion = IntervencionCandidato::where('candidato_id', $candidato->id)->firstOrFail();

    expect($intervencion->ruta)->toBe(RutaIntervencionCandidato::Regional)
        ->and($intervencion->estado)->toBe(EstadoIntervencionCandidato::Pendiente)
        ->and($intervencion->rechazo_rh_motivo)->toBe('No cumple el perfil solicitado.')
        ->and($candidato->fresh()->estado)->toBe(EstadoCandidato::RechazadoRh);

    Notification::assertSentTo($regional, PendienteRhNotification::class);
});

test('Gestor/Volante enruta la intervención a Regional; un puesto superior la enruta a Dirección Comercial', function () {
    $this->estructura['puesto']->update(['grupo_indicador' => GrupoPuestoIndicador::Gestores]);
    $candidatoGestor = icCandidatoRechazadoPorRh($this);

    app(IntervencionCandidatoService::class)->solicitar($candidatoGestor, $this->gerente, 'Reconsiderar.');
    $intervencionGestor = IntervencionCandidato::where('candidato_id', $candidatoGestor->id)->firstOrFail();
    expect($intervencionGestor->ruta)->toBe(RutaIntervencionCandidato::Regional);

    // Mismo flujo, puesto SIN grupo Gestores (p. ej. Subgerente).
    $estructuraSuperior = clEstructura();
    $gerenteSuperior = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructuraSuperior['sucursal']->id]);
    $t2 = (object) ['rh' => $this->rh, 'estructura' => $estructuraSuperior, 'gerente' => $gerenteSuperior];
    $candidatoSuperior = icCandidatoRechazadoPorRh($t2);

    app(IntervencionCandidatoService::class)->solicitar($candidatoSuperior, $gerenteSuperior, 'Reconsiderar.');
    $intervencionSuperior = IntervencionCandidato::where('candidato_id', $candidatoSuperior->id)->firstOrFail();
    expect($intervencionSuperior->ruta)->toBe(RutaIntervencionCandidato::DireccionComercial);
});

test('Dirección Comercial aprueba la intervención: el candidato pasa a autorizado y queda auditado', function () {
    $candidato = icCandidatoRechazadoPorRh($this); // puesto por defecto: no es Gestores -> ruta Dirección Comercial.
    $director = icDireccionComercial();
    $director->givePermissionTo('candidatos.intervencion_decidir');

    app(IntervencionCandidatoService::class)->solicitar($candidato, $this->gerente, 'Reconsiderar, buen candidato.');
    $intervencion = IntervencionCandidato::where('candidato_id', $candidato->id)->firstOrFail();

    $this->actingAs($director)
        ->post(route('rh.intervenciones.decidir', $intervencion), ['aprueba' => true, 'comentario' => 'Autorizo la excepción.'])
        ->assertSessionHasNoErrors();

    $intervencion->refresh();

    expect($candidato->fresh()->estado)->toBe(EstadoCandidato::AutorizadoRh)
        ->and($intervencion->estado)->toBe(EstadoIntervencionCandidato::Aprobada)
        ->and($intervencion->aprobador_id)->toBe($director->id);
});

test('Dirección Comercial confirma el rechazo: el candidato sigue rechazado', function () {
    $candidato = icCandidatoRechazadoPorRh($this);
    $director = icDireccionComercial();
    $director->givePermissionTo('candidatos.intervencion_decidir');

    app(IntervencionCandidatoService::class)->solicitar($candidato, $this->gerente, 'Reconsiderar.');
    $intervencion = IntervencionCandidato::where('candidato_id', $candidato->id)->firstOrFail();

    $this->actingAs($director)
        ->post(route('rh.intervenciones.decidir', $intervencion), ['aprueba' => false])
        ->assertSessionHasNoErrors();

    expect($candidato->fresh()->estado)->toBe(EstadoCandidato::RechazadoRh)
        ->and($intervencion->fresh()->estado)->toBe(EstadoIntervencionCandidato::RechazoConfirmado);
});

test('un Regional de otra región no puede decidir una intervención que no le corresponde', function () {
    $this->estructura['puesto']->update(['grupo_indicador' => GrupoPuestoIndicador::Gestores]);
    $candidato = icCandidatoRechazadoPorRh($this);

    $otraSucursal = Sucursal::factory()->create();
    $regionalOtraRegion = icRegionalDe($otraSucursal->id);
    $regionalOtraRegion->givePermissionTo('candidatos.intervencion_decidir');

    app(IntervencionCandidatoService::class)->solicitar($candidato, $this->gerente, 'Reconsiderar.');
    $intervencion = IntervencionCandidato::where('candidato_id', $candidato->id)->firstOrFail();

    $this->actingAs($regionalOtraRegion)
        ->post(route('rh.intervenciones.decidir', $intervencion), ['aprueba' => true])
        ->assertForbidden();

    expect($intervencion->fresh()->estado)->toBe(EstadoIntervencionCandidato::Pendiente);
});

test('no se puede solicitar una segunda intervención mientras haya una pendiente de decisión', function () {
    $candidato = icCandidatoRechazadoPorRh($this);

    app(IntervencionCandidatoService::class)->solicitar($candidato, $this->gerente, 'Primera solicitud.');

    $this->actingAs($this->gerente)
        ->post(route('rh.candidatos.intervencion.solicitar', $candidato), ['motivo' => 'Segunda solicitud.'])
        ->assertSessionHasErrors('estado');

    expect(IntervencionCandidato::where('candidato_id', $candidato->id)->count())->toBe(1);
});

test('la intervención aprobada queda inmutable: no se puede decidir dos veces', function () {
    $candidato = icCandidatoRechazadoPorRh($this);
    $director = icDireccionComercial();
    $director->givePermissionTo('candidatos.intervencion_decidir');

    app(IntervencionCandidatoService::class)->solicitar($candidato, $this->gerente, 'Reconsiderar.');
    $intervencion = IntervencionCandidato::where('candidato_id', $candidato->id)->firstOrFail();

    app(IntervencionCandidatoService::class)->decidir($intervencion, $director, true, null);

    $this->actingAs($director)
        ->post(route('rh.intervenciones.decidir', $intervencion), ['aprueba' => false])
        ->assertSessionHasErrors('estado');

    expect($candidato->fresh()->estado)->toBe(EstadoCandidato::AutorizadoRh);
});
