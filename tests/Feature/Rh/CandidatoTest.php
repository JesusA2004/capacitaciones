<?php

use App\Enums\EstadoCandidato;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\PreguntaFiltroCandidato;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use Database\Seeders\MotivosRechazoCandidatoSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Validation\ValidationException;

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
            // Sin vacante solo es válido si es explícitamente espontáneo.
            'espontaneo' => true,
            'fuente' => 'referido',
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

test('registrar un candidato sin vacante exige marcarlo explícitamente como espontáneo', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Sin Vacante'])
        ->assertSessionHasErrors('vacante_id');

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Espontáneo', 'espontaneo' => true, 'fuente' => 'referido'])
        ->assertSessionHasNoErrors();

    $candidato = Candidato::where('nombre', 'Espontáneo')->firstOrFail();
    expect($candidato->espontaneo)->toBeTrue()
        ->and($candidato->vacante_id)->toBeNull();
});

test('una vacante ya cubierta se rechaza al guardar el candidato, race-safe', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $vacante = Vacante::factory()->create([
        'estado' => 'cubierta',
        'plazas_requeridas' => 1,
        'plazas_disponibles' => 0,
        'plazas_cubiertas' => 1,
    ]);

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Tarde', 'vacante_id' => $vacante->id, 'fuente' => 'referido'])
        ->assertSessionHasErrors('vacante_id');

    expect(Candidato::where('nombre', 'Tarde')->exists())->toBeFalse();
});

test('el listado de vacantes del formulario de candidato nunca incluye una vacante cubierta', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $abierta = Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 1]);
    $cubierta = Vacante::factory()->create(['estado' => 'cubierta', 'plazas_disponibles' => 0]);

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertInertia(fn ($page) => $page
            ->where('opciones.vacantes', fn ($vacantes) => collect($vacantes)->pluck('id')->contains($abierta->id)
                && ! collect($vacantes)->pluck('id')->contains($cubierta->id)));
});

test('un candidato espontáneo no puede iniciar contratación sin vincularse antes a una vacante', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $candidato = Candidato::factory()->create([
        'vacante_id' => null,
        'espontaneo' => true,
        'estado' => EstadoCandidato::AutorizadoRh,
    ]);

    expect(fn () => app(ContratacionCandidatoService::class)->iniciarContratacion(
        $candidato,
        ['sucursal_principal_id' => Sucursal::factory()->create()->id, 'puesto_id' => Puesto::factory()->create()->id],
        $usuario,
    ))->toThrow(ValidationException::class);
});

/*
 * Regresión: el formulario web de candidato no ofrecía la campaña, así que
 * ningún candidato quedaba atribuido y el embudo de cada campaña
 * (contactados, entrevistas, psicométricos, contratados) salía en ceros.
 */
test('el formulario de candidato ofrece las campañas de cada vacante real', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $vacante = Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 1]);
    $campana = CampanaReclutamiento::query()->create([
        'vacante_id' => $vacante->id, 'canal' => 'meta', 'mes' => 10, 'anio' => 2026,
        'fecha_inicio' => '2026-10-01', 'monto' => 4500, 'nombre' => 'Gestores octubre',
    ]);

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertInertia(fn ($page) => $page
            ->where('opciones.campanas', fn ($campanas) => collect($campanas)->contains(
                fn ($c) => $c['id'] === $campana->id && $c['vacante_id'] === $vacante->id && str_contains($c['etiqueta'], 'Gestores octubre'),
            )));
});

test('un candidato se liga a la campaña de su vacante y no a la de otra', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $vacante = Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 1]);
    $otraVacante = Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 1]);
    $campanaPropia = CampanaReclutamiento::query()->create(['vacante_id' => $vacante->id, 'canal' => 'meta', 'mes' => 10, 'anio' => 2026, 'fecha_inicio' => '2026-10-01', 'monto' => 100]);
    $campanaAjena = CampanaReclutamiento::query()->create(['vacante_id' => $otraVacante->id, 'canal' => 'indeed', 'mes' => 10, 'anio' => 2026, 'fecha_inicio' => '2026-10-01', 'monto' => 100]);

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Ajena', 'vacante_id' => $vacante->id, 'campana_reclutamiento_id' => $campanaAjena->id])
        ->assertSessionHasErrors('campana_reclutamiento_id');

    expect(Candidato::where('nombre', 'Ajena')->exists())->toBeFalse();

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Propia', 'vacante_id' => $vacante->id, 'campana_reclutamiento_id' => $campanaPropia->id])
        ->assertSessionHasNoErrors();

    expect(Candidato::where('nombre', 'Propia')->value('campana_reclutamiento_id'))->toBe($campanaPropia->id);
});

/*
 * Origen obligatorio al registrar: de qué campaña viene o, si no fue por
 * campaña, el medio por el que llegó. Con campaña, la fuente sale del canal.
 */
test('registrar un candidato exige la campaña de la que viene o el medio por el que llegó', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $vacante = Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 2]);
    $campana = CampanaReclutamiento::query()->create(['vacante_id' => $vacante->id, 'canal' => 'indeed', 'mes' => 10, 'anio' => 2026, 'fecha_inicio' => '2026-10-01', 'monto' => 900]);

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Sin Origen', 'vacante_id' => $vacante->id])
        ->assertSessionHasErrors('fuente');

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'De Campana', 'vacante_id' => $vacante->id, 'campana_reclutamiento_id' => $campana->id, 'fuente' => 'meta'])
        ->assertSessionHasNoErrors();

    // La fuente nunca contradice a la campaña: sale de su canal.
    expect(Candidato::where('nombre', 'De Campana')->value('fuente'))->toBe('indeed');
});

test('una campaña general (sin vacante) se ofrece y se puede atribuir a cualquier candidato', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 1]);
    $general = CampanaReclutamiento::query()->create(['canal' => 'meta', 'mes' => 10, 'anio' => 2026, 'fecha_inicio' => now()->toDateString(), 'monto' => 500]);

    $this->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertInertia(fn ($page) => $page->where('opciones.campanas', fn ($campanas) => collect($campanas)->contains(fn ($c) => $c['id'] === $general->id && $c['vacante_id'] === null)));

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Espontaneo General', 'espontaneo' => true, 'campana_reclutamiento_id' => $general->id])
        ->assertSessionHasNoErrors();

    expect(Candidato::where('nombre', 'Espontaneo General')->value('campana_reclutamiento_id'))->toBe($general->id);
});

test('si no cumple un requisito indispensable se registra igual pero queda no viable con motivo de catálogo', function () {
    $this->seed(MotivosRechazoCandidatoSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $vacante = Vacante::factory()->real()->create(['estado' => 'abierta', 'plazas_disponibles' => 2]);
    $pregunta = PreguntaFiltroCandidato::query()->create([
        'clave' => 'maneja_moto', 'pregunta' => '¿Sabe manejar moto?', 'puestos' => [$vacante->puesto_id],
        'respuesta_requerida' => true, 'eliminatoria' => true, 'activo' => true,
    ]);

    // Sin contestar la pregunta del puesto no se registra.
    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Sin Respuesta', 'vacante_id' => $vacante->id, 'fuente' => 'referido'])
        ->assertSessionHasErrors("respuestas_filtro.{$pregunta->id}");

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Sin Moto', 'vacante_id' => $vacante->id, 'fuente' => 'referido', 'respuestas_filtro' => [$pregunta->id => false]])
        ->assertSessionHasNoErrors();

    $sinMoto = Candidato::where('nombre', 'Sin Moto')->with('motivoRechazo', 'respuestasFiltro')->firstOrFail();
    expect($sinMoto->estado)->toBe(EstadoCandidato::NoViable)
        ->and($sinMoto->motivoRechazo?->clave)->toBe('no_cumple_requisito')
        ->and($sinMoto->motivo_salida)->toContain('¿Sabe manejar moto?')
        ->and($sinMoto->respuestasFiltro)->toHaveCount(1)
        ->and($sinMoto->respuestasFiltro->first()?->cumple)->toBeFalse();

    $this->actingAs($usuario)
        ->post(route('rh.candidatos.store'), ['nombre' => 'Con Moto', 'vacante_id' => $vacante->id, 'fuente' => 'referido', 'respuestas_filtro' => [$pregunta->id => true]])
        ->assertSessionHasNoErrors();

    expect(Candidato::where('nombre', 'Con Moto')->value('estado'))->toBe(EstadoCandidato::Recibidos);
});

test('una salida sin motivo elegido se clasifica sola según el paso en que se quedó', function () {
    $this->seed(MotivosRechazoCandidatoSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $candidato = Candidato::factory()->create(['estado' => EstadoCandidato::Recibidos->value, 'espontaneo' => true]);

    app(CandidatoWorkflowService::class)->descartar($candidato, $usuario, EstadoCandidato::Desistio, 'Ya no contestó el WhatsApp.');

    expect($candidato->refresh()->motivoRechazo?->clave)->toBe('desistio');
});
