<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\CandidatoPsicometrica;
use App\Models\HeadcountTarget;
use App\Models\Vacante;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Cargar ≠ decidir (cierre 2026-10-15): subir el PDF psicométrico o el
| video/PDF socioeconómico deja la etapa «pendiente de revisión»; solo la
| revisión del responsable (viable / no viable) avanza. Cada evidencia con
| su etapa, nunca mezcladas. Origen «Orgánico» sin campaña. Migración de
| candidatos en preselección sin perder historial.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $this->wf = app(CandidatoWorkflowService::class);
});

test('subir resultados psicométricos NO avanza a socioeconómico: queda en revisión hasta que el responsable decide', function () {
    $c = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'estado' => EstadoCandidato::PsicometricasPendientes, 'gerente_involucrado_id' => $this->gerente->id]);

    $this->wf->registrarResultadosPsicometricas($c, $this->rh, 'En perfil.', [UploadedFile::fake()->create('psicometrico.pdf', 100, 'application/pdf')]);
    expect($c->refresh()->estado)->toBe(EstadoCandidato::RevisionPsicometricas);

    $this->wf->revisarPsicometricas($c, $this->gerente, true, null);
    expect($c->refresh()->estado)->toBe(EstadoCandidato::SocioeconomicoPendiente);
});

test('cargar el socioeconómico NO decide: pendiente de revisión, y solo la revisión viable pasa a referencias', function () {
    $c = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'estado' => EstadoCandidato::SocioeconomicoPendiente, 'gerente_involucrado_id' => $this->gerente->id]);

    // Aunque un cliente viejo mande «resultado», cargar no decide nada.
    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $c), [
        'fecha_visita' => now()->toDateString(),
        'direccion' => 'Calle 1',
        'resultado' => 'viable',
        'evidencias' => [UploadedFile::fake()->create('visita.mp4', 500, 'video/mp4'), UploadedFile::fake()->create('estudio.pdf', 100, 'application/pdf')],
    ])->assertSessionHasNoErrors();

    $c->refresh();
    expect($c->estado)->toBe(EstadoCandidato::SocioeconomicoPendiente)
        ->and($this->wf->socioeconomicoPendiente($c))->not->toBeNull()
        ->and(collect($this->wf->accionesPermitidas($c, $this->gerente))->pluck('clave'))->toContain('revisar_socioeconomico')->not->toContain('registrar_socioeconomico');

    // No se puede cargar otro encima del pendiente.
    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $c), ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 2'])
        ->assertSessionHasErrors('estado');

    $this->actingAs($this->gerente)->post(route('rh.candidatos.socioeconomico.revision', $c), ['viable' => true])->assertSessionHasNoErrors();

    expect($c->refresh()->estado)->toBe(EstadoCandidato::ReferenciasPendientes);
});

test('revisar el socioeconómico sin haberlo cargado se rechaza con un motivo claro', function () {
    $c = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'estado' => EstadoCandidato::SocioeconomicoPendiente, 'gerente_involucrado_id' => $this->gerente->id]);

    $this->actingAs($this->gerente)->post(route('rh.candidatos.socioeconomico.revision', $c), ['viable' => true])->assertSessionHasErrors('estado');
    expect($c->refresh()->estado)->toBe(EstadoCandidato::SocioeconomicoPendiente);
});

test('la ficha y el índice dan accesos rápidos por etapa (psicométrico, socioeconómico, video) sin mezclarlos', function () {
    $c = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'estado' => EstadoCandidato::PsicometricasPendientes, 'gerente_involucrado_id' => $this->gerente->id]);
    $this->wf->registrarResultadosPsicometricas($c, $this->rh, 'En perfil.', [UploadedFile::fake()->create('psicometrico.pdf', 100, 'application/pdf')]);
    $this->wf->revisarPsicometricas($c, $this->gerente, true, null);
    $this->wf->registrarSocioeconomico($c, $this->rh, ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1'], [
        UploadedFile::fake()->create('visita.mp4', 500, 'video/mp4'),
        UploadedFile::fake()->create('estudio.pdf', 100, 'application/pdf'),
    ]);

    $this->actingAs($this->rh)->get(route('rh.candidatos.show', $c))->assertOk()->assertInertia(fn ($page) => $page
        ->where('candidato.accesos_rapidos.psicometrico.nombre', 'psicometrico.pdf')
        ->where('candidato.accesos_rapidos.socioeconomico.nombre', 'estudio.pdf')
        ->where('candidato.accesos_rapidos.video.nombre', 'visita.mp4'));

    $this->actingAs($this->rh)->get(route('rh.candidatos.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('candidatos.0.accesos_rapidos.video.nombre', 'visita.mp4'));
});

test('origen «Orgánico»: sin campaña y sin volver a preguntar el medio', function () {
    HeadcountTarget::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id, 'plantilla_autorizada' => 3]);
    $vacante = Vacante::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id]);

    $this->actingAs($this->rh)->post(route('rh.candidatos.store'), [
        'vacante_id' => $vacante->id,
        'nombre' => 'Orgánico',
        'apellidos' => 'Prueba',
        'fuente' => 'organico',
        'campana_reclutamiento_id' => null,
    ])->assertSessionHasNoErrors();

    $candidato = Candidato::query()->where('nombre', 'Orgánico')->firstOrFail();
    expect($candidato->fuente)->toBe('organico')
        ->and($candidato->campana_reclutamiento_id)->toBeNull()
        ->and($candidato->sucursal_id)->toBe($this->estructura['sucursal']->id);
});

test('la migración pasa a autorización de RH a quien estaba en preselección, sin perder su historial', function () {
    $c = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'estado' => EstadoCandidato::ReferenciasPendientes]);
    DB::table('candidatos')->where('id', $c->id)->update(['estado' => 'preseleccion_gerente']);
    $tipo = $c->getMorphClass();
    DB::table('aprobaciones')->insert(['aprobable_type' => $tipo, 'aprobable_id' => $c->id, 'proceso' => 'seleccion_candidato', 'etapa' => 'preautorizacion', 'ronda' => 1, 'candidato_id' => $c->id, 'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()]);

    $migracion = require database_path('migrations/2026_10_15_120000_retira_preautorizacion_y_separa_revision_socioeconomico.php');
    (fn () => $this->migrarPreselecciones())->call($migracion);

    expect($c->refresh()->estado)->toBe(EstadoCandidato::AutorizacionRhPendiente)
        ->and(DB::table('aprobaciones')->where('aprobable_id', $c->id)->where('etapa', 'preautorizacion')->value('estado'))->toBe('omitido')
        ->and(DB::table('aprobaciones')->where('aprobable_id', $c->id)->where('etapa', 'autorizacion_rh')->value('estado'))->toBe('pendiente')
        ->and($c->seguimientos()->where('estado_anterior', 'preseleccion_gerente')->exists())->toBeTrue();
});

test('ya no existe la ruta para preautorizar candidatos', function () {
    $c = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'estado' => EstadoCandidato::AutorizacionRhPendiente]);

    $this->actingAs($this->gerente)->post('/rh/candidatos/'.$c->id.'/preautorizar')->assertNotFound();
    expect(CandidatoPsicometrica::query()->count())->toBe(0);
});
