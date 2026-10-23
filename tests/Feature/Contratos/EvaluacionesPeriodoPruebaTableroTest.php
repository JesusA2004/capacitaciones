<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\VencimientoContratosService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Evaluaciones de periodo de prueba» como UN solo módulo (2026-10-22):
| pestañas Próximas / por asignar (antes «Periodos por vencer»), En proceso
| y Evaluadas; «Asignar evaluación» antes del scheduler; PDF para firma del
| FO-RH-EPP-001 y la redirección de la ruta vieja rh.periodos-prueba.index.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $sucursal = $this->estructura['sucursal']->id;
    $this->hoy = CarbonImmutable::now('America/Mexico_City')->startOfDay();

    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal]);
    // El jefe directo sale del organigrama: Gestor reporta a Gerente de sucursal.
    $gerencia = Puesto::factory()->create(['nombre' => 'Gerente de sucursal']);
    $this->jefe = clUsuario('jefe_directo', ['sucursal_principal_id' => $sucursal, 'puesto_id' => $gerencia->id, 'estatus' => 'activo']);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2, 'puesto_superior_id' => $gerencia->id]);

    // Ingresó hace ~50 días: su periodo de 2 meses termina en ~10 días.
    $ingreso = $this->hoy->subDays(50);
    $this->persona = Colaborador::factory()->create([
        'estatus' => 'activo',
        'estado_alta' => null,
        'puesto_id' => $this->gestor->id,
        'sucursal_principal_id' => $sucursal,
        'jefe_id' => $this->jefe->colaborador_id,
        'fecha_ingreso' => $ingreso->toDateString(),
    ]);
    $this->contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $this->persona->id,
        'fecha_inicio' => $ingreso->toDateString(),
        'fecha_fin' => $ingreso->addMonthsNoOverflow(2)->subDay()->toDateString(),
    ]);
    // Autoevaluación: la persona evaluada tiene cuenta y la contesta ella.
    $this->personaUsuario = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->personaUsuario->assignRole('colaborador');
});

/**
 * Captura oficial FO-RH-EPP-001: todos los criterios con la misma
 * calificación y las preguntas filtro en «No».
 *
 * @return array<string, mixed>
 */
function eptCaptura(int $calificacion = 4): array
{
    return [
        'criterios' => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
            ->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => $calificacion])->all(),
        'filtros' => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
            ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => false])->all(),
        'observaciones' => 'Buen desempeño.',
    ];
}

test('Próximas lista a quien termina su periodo sin evaluación y «Asignar evaluación» la crea y avisa al COLABORADOR (la contesta él)', function () {
    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Evaluaciones/Index')
            ->where('pestana', 'proximas')
            ->where('puedeAsignar', true)
            ->where('porAsignar.data.0.id', $this->persona->id)
            ->where('porAsignar.data.0.puede_asignar', true)
            ->where('porAsignar.data.0.evaluador', $this->persona->nombreCompleto())
            ->where('porAsignar.data.0.tiene_cuenta', true)
            ->where('conteos.por_asignar', 1));

    $this->actingAs($this->rh)
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [$this->persona->id]])
        ->assertRedirect()
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'success');

    $evaluacion = EvaluacionPeriodoPrueba::query()->sole();
    expect($evaluacion->contrato_laboral_id)->toBe($this->contrato->id)
        ->and($evaluacion->evaluador_colaborador_id)->toBe($this->persona->id)
        ->and($evaluacion->estado)->toBe(EstadoEvaluacionPrueba::Pendiente)
        ->and($this->contrato->refresh()->aviso_vencimiento_en)->not->toBeNull();

    Notification::assertSentTo($this->personaUsuario, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->personaUsuario)['tipo'] === 'evaluacion_pendiente');
    Notification::assertNotSentTo($this->jefe, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->jefe)['tipo'] === 'evaluacion_pendiente');

    // Ya no está por asignar; aparece en «En proceso · Esperando al colaborador»
    // (el enlace viejo ?subfiltro=esperando_jefe cae en el mismo chip).
    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index', ['tab' => 'en_proceso', 'subfiltro' => 'esperando_jefe']))
        ->assertInertia(fn (Assert $p) => $p
            ->where('conteos.por_asignar', 0)
            ->where('conteos.esperando_colaborador', 1)
            ->where('filtros.subfiltro', 'esperando_colaborador')
            ->where('evaluaciones.data.0.id', $evaluacion->id));

    // Volver a asignar no duplica (una evaluación por contrato) ni re-avisa al scheduler.
    $this->actingAs($this->rh)
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [$this->persona->id]])
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'error');
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect(EvaluacionPeriodoPrueba::query()->count())->toBe(1);
});

test('sin contrato vigente no se puede asignar y se explica por qué', function () {
    $sinContrato = Colaborador::factory()->create([
        'estatus' => 'activo',
        'estado_alta' => null,
        'puesto_id' => $this->gestor->id,
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'jefe_id' => $this->jefe->colaborador_id,
        'fecha_ingreso' => $this->hoy->subDays(40)->toDateString(),
    ]);

    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index', ['tab' => 'proximas', 'busqueda' => $sinContrato->name]))
        ->assertInertia(fn (Assert $p) => $p
            ->where('porAsignar.data.0.id', $sinContrato->id)
            ->where('porAsignar.data.0.puede_asignar', false)
            ->where('porAsignar.data.0.motivo_no_asignable', fn (string $m) => str_contains($m, 'contrato')));

    $this->actingAs($this->rh)
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [$sinContrato->id]])
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'error' && str_contains($t['message'], 'contrato'));

    expect(EvaluacionPeriodoPrueba::query()->where('colaborador_id', $sinContrato->id)->exists())->toBeFalse();
});

test('asignar en lote procesa a cada uno y no se detiene por quien aún no se puede', function () {
    $sinContrato = Colaborador::factory()->create([
        'estatus' => 'activo',
        'puesto_id' => $this->gestor->id,
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'fecha_ingreso' => $this->hoy->subDays(40)->toDateString(),
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [$this->persona->id, $sinContrato->id]])
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'warning');

    expect(EvaluacionPeriodoPrueba::query()->pluck('colaborador_id')->all())->toBe([$this->persona->id]);
});

test('sin permiso de asignar responde 403 y no crea nada', function () {
    // El jefe directo contesta la evaluación, pero no la asigna.
    $this->actingAs($this->jefe)
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [$this->persona->id]])
        ->assertForbidden();

    $this->actingAs(clUsuario('colaborador'))
        ->post(route('rh.evaluaciones.asignar'), ['colaboradores' => [$this->persona->id]])
        ->assertForbidden();
    $this->actingAs(clUsuario('colaborador'))->get(route('rh.evaluaciones.index'))->assertForbidden();

    expect(EvaluacionPeriodoPrueba::query()->count())->toBe(0);
});

test('Evaluadas muestra las respuestas guardadas y el PDF para firma se ve en línea y se descarga', function () {
    $evaluacion = app(VencimientoContratosService::class)->asignarAhora($this->persona, $this->rh);
    app(EvaluacionPeriodoPruebaService::class)->contestar($evaluacion, $this->personaUsuario, eptCaptura(4));

    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index', ['tab' => 'evaluadas']))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('pestana', 'evaluadas')
            ->where('evaluaciones.data.0.id', $evaluacion->id)
            ->where('evaluaciones.data.0.respuestas.recomienda_renovar', true)
            ->has('evaluaciones.data.0.respuestas.criterios', count(config('contratos.evaluacion_periodo_prueba_v2.criterios')))
            ->where('evaluaciones.data.0.pdf_url', route('rh.evaluaciones.pdf', $evaluacion->id)));

    // Compatibilidad: «Evaluación y decisión» (?estado=capturada) cae en En proceso · Por decidir RH.
    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index', ['estado' => 'capturada']))
        ->assertInertia(fn (Assert $p) => $p
            ->where('pestana', 'en_proceso')
            ->where('filtros.subfiltro', 'por_decidir')
            ->where('evaluaciones.data.0.id', $evaluacion->id));

    $vista = $this->actingAs($this->rh)->get(route('rh.evaluaciones.pdf', $evaluacion->id));
    $vista->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($vista->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and(substr((string) $vista->getContent(), 0, 4))->toBe('%PDF');

    $descarga = $this->actingAs($this->rh)->get(route('rh.evaluaciones.pdf', ['evaluacion' => $evaluacion->id, 'descargar' => 1]));
    expect($descarga->headers->get('Content-Disposition'))->toStartWith('attachment');

    // Su jefe directo también puede imprimirla (para firmar de enterado).
    $this->actingAs($this->jefe)->get(route('rh.evaluaciones.pdf', $evaluacion->id))->assertOk();
});

test('el PDF para firma responde 403 sin permiso o fuera de alcance, y 422 si aún no hay respuestas', function () {
    $evaluacion = app(VencimientoContratosService::class)->asignarAhora($this->persona, $this->rh);

    $this->actingAs($this->rh)->getJson(route('rh.evaluaciones.pdf', $evaluacion->id))->assertUnprocessable();

    app(EvaluacionPeriodoPruebaService::class)->contestar($evaluacion, $this->personaUsuario, eptCaptura(4));

    $this->actingAs(clUsuario('colaborador'))->get(route('rh.evaluaciones.pdf', $evaluacion->id))->assertForbidden();
    // Otro jefe sin relación con el colaborador.
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $this->actingAs(clUsuario('jefe_directo', ['sucursal_principal_id' => $otraSucursal->id]))
        ->get(route('rh.evaluaciones.pdf', $evaluacion->id))
        ->assertForbidden();
    // El propio colaborador evaluado nunca la imprime desde aquí.
    $this->actingAs($this->personaUsuario)->get(route('rh.evaluaciones.pdf', $evaluacion->id))->assertForbidden();
});

test('«Periodos por vencer» redirige a la pestaña Próximas conservando los filtros', function () {
    $this->actingAs($this->rh)
        ->get(route('rh.periodos-prueba.index', ['busqueda' => 'Ana', 'solo_alerta' => 1]))
        ->assertRedirect(route('rh.evaluaciones.index', ['tab' => 'proximas', 'busqueda' => 'Ana', 'solo_alerta' => 1]));

    $this->actingAs(clUsuario('colaborador'))->get(route('rh.periodos-prueba.index'))->assertForbidden();
});
