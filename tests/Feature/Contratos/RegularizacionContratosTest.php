<?php

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoLoteContratos;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\LoteGeneracionContratos;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Contratos\PeriodosPruebaService;
use App\Services\Contratos\RegularizacionContratosService;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/*
| Módulo «Contratos» (RegularizacionContratosService + LoteContratosService)
| y «Periodos por vencer» (PeriodosPruebaService): qué contratos le tocan a
| cada colaborador activo por fecha de ingreso y puesto, «Generar todos»
| idempotente y periodos detectados por fecha de ingreso.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $this->hoy = CarbonImmutable::now('America/Mexico_City')->startOfDay();

    // Plantillas heredadas activas (texto de prueba): sin masters de
    // Jurídico el motor usa la plantilla que RH tenga cargada.
    foreach (['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia', 'contrato_indeterminado'] as $clave) {
        clPlantilla($clave);
    }

    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2, 'grupo_documental' => 'gestor']);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'meses_periodo_prueba' => 3, 'grupo_documental' => 'gerente']);
});

/**
 * @param  array<string, mixed>  $atributos
 */
function rcActivo(Puesto $puesto, CarbonImmutable $ingreso, array $atributos = []): Colaborador
{
    return Colaborador::factory()->create([
        'estatus' => 'activo',
        'estado_alta' => null,
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => Sucursal::query()->where('nombre', 'Cuernavaca')->value('id'),
        'fecha_ingreso' => $ingreso->toDateString(),
        'sueldo_mensual' => 12000,
        ...$atributos,
    ]);
}

// --- Cálculo por fecha de ingreso y puesto ---------------------------------

test('dentro del periodo de prueba solo le corresponde el contrato inicial con su paquete', function () {
    $ingreso = $this->hoy->subDays(10);
    $colaborador = rcActivo($this->gestor, $ingreso);

    $plan = app(RegularizacionContratosService::class)->plan($colaborador, $this->hoy);

    expect($plan['periodo_vencido'])->toBeFalse()
        ->and($plan['contratos'])->toHaveCount(1)
        ->and($plan['contratos'][0]['tipo'])->toBe(TipoContratacion::CapacitacionInicial->value)
        ->and($plan['contratos'][0]['fecha_inicio'])->toBe($ingreso->toDateString())
        // Gestor: 2 meses (puestos.meses_periodo_prueba) − 1 día.
        ->and($plan['contratos'][0]['fecha_fin'])->toBe($ingreso->addMonthsNoOverflow(2)->subDay()->toDateString())
        ->and(array_column($plan['contratos'][0]['documentos'], 'clave'))->toBe(['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia'])
        ->and($plan['estado'])->toBe('por_generar');
});

test('si ya pasó el periodo de prueba también le corresponde el indeterminado desde el día siguiente', function () {
    $ingreso = $this->hoy->subMonths(5);
    $colaborador = rcActivo($this->gerente, $ingreso);
    $fin = $ingreso->addMonthsNoOverflow(3)->subDay();

    $plan = app(RegularizacionContratosService::class)->plan($colaborador, $this->hoy);

    expect($plan['periodo_vencido'])->toBeTrue()
        ->and($plan['fin_periodo_prueba'])->toBe($fin->toDateString())
        ->and($plan['contratos'])->toHaveCount(2)
        ->and($plan['contratos'][1]['tipo'])->toBe(TipoContratacion::Indeterminado->value)
        ->and($plan['contratos'][1]['fecha_inicio'])->toBe($fin->addDay()->toDateString())
        ->and($plan['contratos'][1]['fecha_fin'])->toBeNull()
        ->and(array_column($plan['contratos'][1]['documentos'], 'clave'))->toBe(['contrato_indeterminado']);
});

test('un puesto sin duración de periodo de prueba queda como «faltan datos» con el motivo', function () {
    $puesto = Puesto::factory()->sinDuracionCapacitacion()->create(['grupo_documental' => 'gestor']);
    $colaborador = rcActivo($puesto, $this->hoy->subMonths(4));

    $plan = app(RegularizacionContratosService::class)->plan($colaborador, $this->hoy);

    expect($plan['estado'])->toBe('faltan_datos')
        ->and($plan['generable'])->toBeFalse()
        ->and($plan['bloqueos'][0]['etiqueta'])->toBe('Duración del periodo de prueba del puesto');
});

test('personal migrado con contrato inicial registrado y periodo vencido: el indeterminado se genera sin evaluación', function () {
    $ingreso = $this->hoy->subYears(4);
    $colaborador = rcActivo($this->gestor, $ingreso);
    $inicial = ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => $ingreso->toDateString(),
        'fecha_fin' => $ingreso->addMonthsNoOverflow(2)->subDay()->toDateString(),
        'estado' => EstadoContratoLaboral::Vigente,
    ]);

    $plan = app(RegularizacionContratosService::class)->plan($colaborador, $this->hoy);

    expect($plan['estado'])->toBe('por_generar')
        ->and($plan['contratos'][1]['pendiente_evaluacion'])->toBeFalse()
        ->and($plan['contratos'][1]['documentos'][0]['estado'])->toBe('por_generar');

    $this->actingAs($this->rh)->post(route('rh.contratos.generar', $colaborador))->assertRedirect();

    $indeterminado = ContratoLaboral::query()->where('contrato_anterior_id', $inicial->id)->firstOrFail();

    expect($inicial->refresh()->estado)->toBe(EstadoContratoLaboral::Renovado)
        ->and($indeterminado->tipo)->toBe(TipoContratacion::Indeterminado)
        ->and($indeterminado->fecha_inicio->toDateString())->toBe($ingreso->addMonthsNoOverflow(2)->toDateString());
});

test('con una evaluación de periodo de prueba en curso no se brinca el flujo normal', function () {
    $ingreso = $this->hoy->subMonths(2)->subDays(3);
    $colaborador = rcActivo($this->gestor, $ingreso);
    $inicial = ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => $ingreso->toDateString(),
        'fecha_fin' => $ingreso->addMonthsNoOverflow(2)->subDay()->toDateString(),
        'estado' => EstadoContratoLaboral::Vigente,
    ]);
    EvaluacionPeriodoPrueba::query()->create([
        'contrato_laboral_id' => $inicial->id,
        'colaborador_id' => $colaborador->id,
        'estado' => EstadoEvaluacionPrueba::Pendiente,
        'fecha_limite' => $inicial->fecha_fin?->toDateString(),
    ]);

    $plan = app(RegularizacionContratosService::class)->plan($colaborador, $this->hoy);

    expect($plan['contratos'][1]['pendiente_evaluacion'])->toBeTrue()
        ->and($plan['contratos'][1]['documentos'][0]['estado'])->toBe('pendiente_evaluacion');
});

test('un contrato firmado cargado en el expediente se informa pero no impide generar el del sistema', function () {
    $this->seed(DocumentTypeSeeder::class);
    $colaborador = rcActivo($this->gestor, $this->hoy->subDays(15));
    EmployeeDocument::factory()->aprobado()->create([
        'user_id' => null,
        'colaborador_id' => $colaborador->id,
        'document_type_id' => DocumentType::query()->where('clave', 'contrato')->value('id'),
    ]);

    $plan = app(RegularizacionContratosService::class)->plan($colaborador, $this->hoy);
    $principal = $plan['contratos'][0]['documentos'][0];

    expect($principal['clave'])->toBe('contrato_capacitacion')
        ->and($principal['estado'])->toBe('por_generar')
        ->and($principal['firmado_cargado'])->not->toBeNull()
        ->and($principal['firmado_cargado'])->toHaveKeys(['url', 'nombre', 'mime']);

    $this->actingAs($this->rh)->post(route('rh.contratos.generar', $colaborador))->assertRedirect();

    expect(GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->where('clave_plantilla', 'contrato_capacitacion')->count())->toBe(1);
});

// --- Generar todos: idempotente y omite incompletos con motivo -------------

test('«Generar todos» genera solo lo que falta, omite al incompleto con motivo y no duplica al repetirse', function () {
    $completo = rcActivo($this->gestor, $this->hoy->subMonths(6));
    $sinDuracion = rcActivo(Puesto::factory()->sinDuracionCapacitacion()->create(), $this->hoy->subMonths(6));

    $this->actingAs($this->rh)->post(route('rh.contratos.generar-todos'))->assertRedirect();

    $lote = LoteGeneracionContratos::query()->latest('id')->firstOrFail();
    $resultados = collect($lote->resultados)->keyBy('colaborador_id');

    expect($lote->estado)->toBe(EstadoLoteContratos::Completado)
        ->and($resultados[$completo->id]['resultado'])->toBe('generado')
        ->and($resultados[$sinDuracion->id]['resultado'])->toBe('omitido')
        ->and($resultados[$sinDuracion->id]['motivo'])->toContain('duración');

    // Contrato inicial (renovado) + indeterminado vigente desde fin + 1.
    $contratos = ContratoLaboral::query()->where('colaborador_id', $completo->id)->orderBy('id')->get();
    $fin = $this->hoy->subMonths(6)->addMonthsNoOverflow(2)->subDay();

    expect($contratos)->toHaveCount(2)
        ->and($contratos[0]->estado)->toBe(EstadoContratoLaboral::Renovado)
        ->and($contratos[0]->fecha_inicio->toDateString())->toBe($this->hoy->subMonths(6)->toDateString())
        ->and($contratos[1]->tipo)->toBe(TipoContratacion::Indeterminado)
        ->and($contratos[1]->fecha_inicio->toDateString())->toBe($fin->addDay()->toDateString());

    $claves = GeneratedDocument::query()->where('colaborador_id', $completo->id)->pluck('clave_plantilla')->sort()->values()->all();
    expect($claves)->toBe(['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_indeterminado', 'contrato_no_competencia'])
        ->and(ContratoLaboral::query()->where('colaborador_id', $sinDuracion->id)->exists())->toBeFalse();

    // Segunda corrida: nada nuevo.
    $this->actingAs($this->rh)->post(route('rh.contratos.generar-todos'))->assertRedirect();
    $segundo = LoteGeneracionContratos::query()->latest('id')->firstOrFail();

    expect(collect($segundo->resultados)->pluck('colaborador_id'))->not->toContain($completo->id)
        ->and($segundo->sin_cambios)->toBeGreaterThanOrEqual(1)
        ->and(GeneratedDocument::query()->where('colaborador_id', $completo->id)->count())->toBe(4)
        ->and(ContratoLaboral::query()->where('colaborador_id', $completo->id)->count())->toBe(2);
});

test('generar por colaborador individual es idempotente', function () {
    $colaborador = rcActivo($this->gestor, $this->hoy->subDays(20));

    $this->actingAs($this->rh)->post(route('rh.contratos.generar', $colaborador))->assertRedirect();
    $this->actingAs($this->rh)->post(route('rh.contratos.generar', $colaborador))->assertRedirect();

    expect(ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and(GeneratedDocument::query()->where('colaborador_id', $colaborador->id)->count())->toBe(3);
});

test('la pantalla lista a los activos con su estado', function () {
    $colaborador = rcActivo($this->gestor, $this->hoy->subDays(5));

    $this->actingAs($this->rh)
        ->get(route('rh.contratos.index', ['busqueda' => $colaborador->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Contratos/Index')
            ->where('filas.data.0.id', $colaborador->id)
            ->where('filas.data.0.estado', 'por_generar')
            ->has('resumen')
            ->where('puedeGenerar', true));
});

// --- Periodos por vencer ----------------------------------------------------

test('«Periodos por vencer» incluye a quien ingresó hace 3 días aunque no tenga contrato', function () {
    $reciente = rcActivo($this->gestor, $this->hoy->subDays(3));
    $antiguo = rcActivo($this->gestor, $this->hoy->subMonths(8));

    $listado = app(PeriodosPruebaService::class)->listar($this->rh, [], $this->hoy);
    $filas = collect($listado['filas']->items())->keyBy('id');

    expect($filas)->toHaveKey($reciente->id)
        ->and($filas)->not->toHaveKey($antiguo->id)
        ->and($filas[$reciente->id]['fin_periodo'])->toBe($this->hoy->subDays(3)->addMonthsNoOverflow(2)->subDay()->toDateString())
        ->and($filas[$reciente->id]['fuente'])->toBe('fecha_ingreso')
        ->and($filas[$reciente->id]['tiene_contrato'])->toBeFalse()
        ->and($filas[$reciente->id]['alerta'])->toBeFalse();
});

test('«Periodos por vencer» alerta a 15 días NATURALES del fin del periodo', function () {
    // Gestor (2 meses): fin = ingreso + 2 meses − 1 día.
    $en10 = rcActivo($this->gestor, $this->hoy->addDays(11)->subMonthsNoOverflow(2));
    $en20 = rcActivo($this->gestor, $this->hoy->addDays(21)->subMonthsNoOverflow(2));

    $filas = collect(app(PeriodosPruebaService::class)->listar($this->rh, [], $this->hoy)['filas']->items())->keyBy('id');

    expect($filas[$en10->id]['dias_restantes'])->toBeLessThanOrEqual(15)
        ->and($filas[$en10->id]['alerta'])->toBeTrue()
        ->and($filas[$en20->id]['dias_restantes'])->toBeGreaterThan(15)
        ->and($filas[$en20->id]['alerta'])->toBeFalse();
});

// --- Permisos ----------------------------------------------------------------

test('sin permiso responde 403 en Contratos, Generar todos y Periodos por vencer', function () {
    $colaborador = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $otro = rcActivo($this->gestor, $this->hoy->subDays(5));

    $this->actingAs($colaborador)->get(route('rh.contratos.index'))->assertForbidden();
    $this->actingAs($colaborador)->post(route('rh.contratos.generar-todos'))->assertForbidden();
    $this->actingAs($colaborador)->post(route('rh.contratos.generar', $otro))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.contratos.vista-previa', [$otro, 'contrato_capacitacion']))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.periodos-prueba.index'))->assertForbidden();

    expect(LoteGeneracionContratos::query()->count())->toBe(0);
});

test('ver contratos no basta para generarlos', function () {
    Permission::findOrCreate('contratos.ver', 'web');
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $lector = User::factory()->create(['colaborador_id' => $persona->id]);
    $lector->givePermissionTo('contratos.ver');
    $otro = rcActivo($this->gestor, $this->hoy->subDays(5));

    $this->actingAs($lector)->get(route('rh.contratos.index'))->assertOk();
    $this->actingAs($lector)->post(route('rh.contratos.generar', $otro))->assertForbidden();
    $this->actingAs($lector)->post(route('rh.contratos.generar-todos'))->assertForbidden();

    expect(ContratoLaboral::query()->where('colaborador_id', $otro->id)->exists())->toBeFalse();
});
