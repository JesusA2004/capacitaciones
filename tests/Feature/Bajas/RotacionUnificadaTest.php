<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Enums\SegmentoRotacion;
use App\Exports\ReporteRhExport;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use App\Models\RotacionCarga;
use App\Models\RotacionMeta;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Services\Bajas\BajaRegistroService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Reportes\TableroRhService;
use App\Services\Rotacion\FormulaRotacion;
use App\Services\Rotacion\RotacionMetaService;
use Carbon\CarbonImmutable;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

/*
| Etapa 1 · Rotación (PDF págs. 12, 13 y 17), escenarios 28-32: una sola
| definición en Tablero, Bajas y exportaciones; la baja cuenta por su último
| día aunque siga abierta; histórico y sistema no se duplican; Global =
| Corporativo + Sucursales en bajas.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow('2026-10-15 12:00:00');
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    config(['bajas.tablero.mes_inicio_sistema' => '2026-09']);

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->corporativo = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id, 'es_corporativo' => true]);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->motor = fn (): TableroRotacionService => app(TableroRotacionService::class);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function rotBaja(Sucursal $sucursal, string $clave, string $fecha, EstatusBaja $estatus = EstatusBaja::Cerrada, bool $rotacion = true, string $ingreso = '2025-01-01'): Baja
{
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'fecha_ingreso' => $ingreso, 'estatus' => EstadoUsuario::Inactivo, 'fecha_baja' => $fecha]);
    $causal = CausalRotacion::query()->where('clave', $clave)->firstOrFail();
    $baja = app(BajaRegistroService::class)->nueva($persona, [
        'forma_salida' => $causal->forma(),
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => $fecha,
        'estatus' => $estatus,
        'es_rotacion' => $rotacion,
        'origen' => OrigenBaja::Sistema,
    ], null);
    $baja->save();

    return $baja;
}

function rotPlantilla(Sucursal $sucursal, string $periodo, int $total): void
{
    PlantillaMensual::query()->create(['periodo' => $periodo, 'anio' => (int) substr($periodo, 0, 4), 'mes' => (int) substr($periodo, 5), 'sucursal_id' => $sucursal->id, 'activos_inicio' => $total, 'activos_cierre' => $total, 'plantilla_promedio' => $total, 'fuente' => 'foto']);
}

function rotExcel(object $test, Sucursal $sucursal, int $anio, int $mes, int $bajas, int $inicial, int $final, ?int $voluntarias = null): void
{
    $carga = RotacionCarga::query()->firstOrCreate(['archivo_hash' => str_repeat('b', 64)], ['nombre_archivo' => 'rotacion.xlsx', 'archivo_ruta' => 'rotacion/b.xlsx', 'estado' => 'aplicada', 'subido_por' => $test->rh->id]);
    RotacionPeriodo::query()->create(['sucursal_id' => $sucursal->id, 'anio' => $anio, 'mes' => $mes, 'sucursal_excel' => $sucursal->nombre, 'plantilla_inicial' => $inicial, 'altas' => 0, 'bajas' => $bajas, 'bajas_voluntarias' => $voluntarias, 'plantilla_final' => $final, 'carga_id' => $carga->id]);
}

test('fórmula única: plantilla promedio y sin plantilla no hay índice ni división entre cero', function () {
    expect(FormulaRotacion::plantillaPromedio(40, 38))->toBe(39.0)
        ->and(FormulaRotacion::indice(2, 39.0))->toBe(5.13)
        ->and(FormulaRotacion::indice(2, 0.0))->toBeNull()
        ->and(FormulaRotacion::indice(2, null))->toBeNull()
        ->and(FormulaRotacion::indice(null, 39.0))->toBeNull();
});

test('escenario 28: una baja en trámite cuenta si su último día ya ocurrió; futura, cancelada e interna no', function () {
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-10-05', EstatusBaja::EnRevisionRh);
    rotBaja($this->sucursal, 'RV-02', '2026-10-06', EstatusBaja::Cerrada);
    rotBaja($this->sucursal, 'RV-03', '2026-10-07', EstatusBaja::Cancelada);
    rotBaja($this->sucursal, 'RV-04', '2026-10-08', EstatusBaja::Rechazada);
    rotBaja($this->sucursal, 'RV-05', '2026-10-09', EstatusBaja::Cerrada, rotacion: false);
    rotBaja($this->sucursal, 'RV-06', '2026-10-25', EstatusBaja::EnRevisionRh);

    $mes = ($this->motor)()->tablero($this->rh, ['desde' => '2026-10', 'hasta' => '2026-10'])['resumen']['mes'];

    expect($mes['bajas'])->toBe(2)
        ->and($mes['indice'])->toBe(4.0)
        ->and($mes['en_curso'])->toBeTrue();
});

test('escenario 29: el Excel manda antes del corte y el sistema desde el corte, sin duplicar', function () {
    rotExcel($this, $this->sucursal, 2026, 8, 3, 50, 46);
    // Una baja del sistema anterior al corte ya está en el Excel: no se suma.
    rotBaja($this->sucursal, 'RV-01', '2026-08-20');
    // Una fila de Excel desde el corte no se suma a las bajas del sistema.
    rotExcel($this, $this->sucursal, 2026, 9, 9, 50, 46);
    rotBaja($this->sucursal, 'NR-02', '2026-09-30');
    rotBaja($this->sucursal, 'RE-01', '2026-10-01');

    $datos = ($this->motor)()->tablero($this->rh, ['desde' => '2026-08', 'hasta' => '2026-10']);
    $serie = collect($datos['tendencia'])->keyBy('periodo');

    expect($serie['2026-08']['fuente'])->toBe('excel')
        ->and($serie['2026-08']['bajas'])->toBe(3)
        ->and($serie['2026-08']['indice'])->toBe(6.25)
        ->and($serie['2026-08']['sin_desglose'])->toBe(3)
        ->and($serie['2026-09']['fuente'])->toBe('sistema')
        ->and($serie['2026-09']['bajas'])->toBe(1)
        // Frontera del corte: la plantilla sigue viniendo del Excel si no hay foto.
        ->and($serie['2026-09']['plantilla_promedio'])->toBeGreaterThanOrEqual(48.0)
        ->and($serie['2026-10']['bajas'])->toBe(1)
        ->and($datos['resumen']['rango']['bajas'])->toBe(5)
        ->and($datos['resumen']['bajas_sistema'])->toBe(2);
});

test('mes sin datos y mes futuro no son 0% ni rompen; el promedio no incluye el mes en curso', function () {
    rotPlantilla($this->sucursal, '2026-09', 50);
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-09-10');
    rotBaja($this->sucursal, 'RV-01', '2026-10-10');
    rotBaja($this->sucursal, 'RV-02', '2026-10-11');

    $datos = ($this->motor)()->tablero($this->rh, ['desde' => '2026-07', 'hasta' => '2026-11']);
    $serie = collect($datos['tendencia'])->keyBy('periodo');

    expect($serie['2026-07']['indice'])->toBeNull()
        ->and($serie['2026-07']['fuente'])->toBe('sin_datos')
        ->and($serie['2026-07']['semaforo'])->toBeNull()
        ->and($serie['2026-11']['indice'])->toBeNull()
        ->and($serie['2026-11']['fuente'])->toBe('sin_datos')
        ->and($serie['2026-10']['en_curso'])->toBeTrue()
        ->and($datos['resumen']['promedio_mensual']['indice'])->toBe(2.0)
        ->and($datos['resumen']['promedio_mensual']['meses'])->toBe(1);
});

test('sin foto ni Excel, la plantilla desde el corte sale de las fechas del sistema', function () {
    Colaborador::factory()->count(10)->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2025-01-01']);

    $mes = ($this->motor)()->mes(CarbonImmutable::create(2026, 10, 1), collect([$this->sucursal->id]), ['forma' => null, 'causal_id' => null, 'puesto_id' => null]);

    expect($mes['fuente'])->toBe('sistema')
        ->and($mes['plantilla_promedio'])->toBeGreaterThanOrEqual(10.0);
});

test('cambio de sucursal: la baja se queda en la sucursal donde ocurrió', function () {
    $baja = rotBaja($this->sucursal, 'RV-01', '2026-10-05');
    $otra = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    Colaborador::query()->whereKey($baja->colaborador_id)->update(['sucursal_principal_id' => $otra->id]);

    $datos = ($this->motor)()->tablero($this->rh, ['desde' => '2026-10', 'hasta' => '2026-10', 'vista' => 'por_sucursal']);
    $filas = collect($datos['sucursales'])->keyBy('sucursal_id');

    expect($filas[$this->sucursal->id]['bajas'])->toBe(1)
        ->and($filas->get($otra->id)['bajas'] ?? 0)->toBe(0);
});

test('escenario 30: Global = Corporativo + Sucursales en bajas; el índice global no es la suma', function () {
    rotPlantilla($this->sucursal, '2026-10', 100);
    rotPlantilla($this->corporativo, '2026-10', 20);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RE-01', '2026-10-03');
    rotBaja($this->corporativo, 'NR-02', '2026-10-04');

    $entrada = ['desde' => '2026-10', 'hasta' => '2026-10'];
    $global = ($this->motor)()->tablero($this->rh, [...$entrada, 'vista' => 'global']);
    $corporativo = ($this->motor)()->tablero($this->rh, [...$entrada, 'vista' => 'corporativo']);
    $sucursales = ($this->motor)()->tablero($this->rh, [...$entrada, 'vista' => 'sucursales']);

    expect($global['resumen']['mes']['bajas'])->toBe($corporativo['resumen']['mes']['bajas'] + $sucursales['resumen']['mes']['bajas'])
        ->and($global['resumen']['comprobacion'])->toBe(['global' => 3, 'corporativo' => 1, 'sucursales' => 2, 'cuadra' => true])
        ->and($global['resumen']['mes']['indice'])->toBe(2.5)
        ->and($corporativo['resumen']['mes']['indice'])->toBe(5.0)
        ->and($sucursales['resumen']['mes']['indice'])->toBe(2.0);
});

test('escenario 31: las cinco vistas responden por la pestaña y Corporativo usa su propia meta', function () {
    rotPlantilla($this->corporativo, '2026-10', 100);
    rotBaja($this->corporativo, 'RV-01', '2026-10-02');
    rotBaja($this->corporativo, 'RV-01', '2026-10-03');

    foreach (TableroRotacionService::VISTAS as $vista) {
        $this->actingAs($this->rh)->get(route('rh.bajas.rotacion', ['vista' => $vista, 'desde' => '2026-10', 'hasta' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Reportes/TableroRotacion')->where('tablero.filtros.vista', $vista)->has('tablero.metas.vigentes', 3));
    }

    // 2% es verde contra la meta global (3%) y ámbar contra la de Corporativo (1.7%).
    $mes = ($this->motor)()->tablero($this->rh, ['vista' => 'corporativo', 'desde' => '2026-10', 'hasta' => '2026-10'])['resumen']['mes'];
    expect($mes['indice'])->toBe(2.0)->and($mes['semaforo'])->toBe('ambar');
});

test('por sucursal: promedio con semáforo, comparativo, Corporativo al final y las bajas que lo componen', function () {
    rotPlantilla($this->sucursal, '2026-09', 50);
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotPlantilla($this->corporativo, '2026-10', 10);
    rotBaja($this->sucursal, 'RV-01', '2026-09-02');
    $baja = rotBaja($this->sucursal, 'RE-01', '2026-10-02');
    rotBaja($this->sucursal, 'RV-02', '2026-10-03');
    rotBaja($this->sucursal, 'RV-03', '2026-10-04');

    $filas = ($this->motor)()->tablero($this->rh, ['vista' => 'por_sucursal', 'desde' => '2026-09', 'hasta' => '2026-10'])['sucursales'];
    $fila = collect($filas)->firstWhere('sucursal_id', $this->sucursal->id);

    expect($filas[array_key_last($filas)]['es_corporativo'])->toBeTrue()
        ->and($fila['promedio_mensual'])->toBe(2.0)
        ->and($fila['semaforo'])->toBe('verde')
        ->and($fila['tendencia'])->toBe('sube')
        ->and($fila['delta'])->toBe(4.0)
        ->and($fila['conteos']['RV'])->toBe(3)
        ->and($fila['conteos']['RE'])->toBe(1)
        ->and(collect($fila['bajas_lista'])->pluck('id')->all())->toContain($baja->id);
});

test('por causales: ranking con cantidad y porcentaje que respeta los filtros', function () {
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RV-01', '2026-10-03');
    rotBaja($this->sucursal, 'RE-01', '2026-10-04');

    $datos = ($this->motor)()->tablero($this->rh, ['vista' => 'por_causales', 'desde' => '2026-10', 'hasta' => '2026-10']);
    expect($datos['causales'][0]['clave'])->toBe('RV-01')
        ->and($datos['causales'][0]['bajas'])->toBe(2)
        ->and($datos['causales'][0]['porcentaje'])->toBe(66.7);

    $soloRe = ($this->motor)()->tablero($this->rh, ['vista' => 'por_causales', 'desde' => '2026-10', 'hasta' => '2026-10', 'forma' => 'RE']);
    expect(collect($soloRe['causales'])->pluck('clave')->all())->toBe(['RE-01']);
});

test('voluntaria contra involuntaria: OT aparte y el Excel solo aporta lo clasificado', function () {
    rotExcel($this, $this->sucursal, 2026, 8, 5, 50, 46, voluntarias: 2);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'NR-02', '2026-10-03');
    rotBaja($this->sucursal, 'OT-03', '2026-10-04');

    $v = ($this->motor)()->tablero($this->rh, ['desde' => '2026-08', 'hasta' => '2026-10'])['resumen']['voluntaria'];

    expect($v)->toMatchArray(['voluntarias' => 3, 'involuntarias' => 1, 'otras' => 1, 'sin_clasificar' => 3, 'total' => 8, 'porcentaje_voluntaria' => 75.0]);
});

test('rotación temprana: menos de 90 días', function () {
    rotBaja($this->sucursal, 'RV-01', '2026-10-02', ingreso: '2026-09-01');
    rotBaja($this->sucursal, 'RV-02', '2026-10-03', ingreso: '2024-01-01');

    $t = ($this->motor)()->tablero($this->rh, ['desde' => '2026-10', 'hasta' => '2026-10'])['resumen']['rotacion_temprana'];

    expect($t)->toBe(['bajas' => 1, 'porcentaje' => 50.0]);
});

test('escenario 32: las exportaciones reproducen la vista y sus filtros', function () {
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RE-01', '2026-10-03');

    foreach (TableroRotacionService::VISTAS as $vista) {
        $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.excel', ['vista' => $vista, 'desde' => '2026-10', 'hasta' => '2026-10']))->assertOk()->assertDownload();
        $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.pdf', ['vista' => $vista, 'desde' => '2026-10', 'hasta' => '2026-10']))->assertOk();
    }

    $mensual = ($this->motor)()->exportable($this->rh, ['vista' => 'global', 'desde' => '2026-10', 'hasta' => '2026-10', 'forma' => 'RV']);
    expect($mensual['titulo'])->toContain('Global')->toContain('2026-10 a 2026-10')->toContain('Renuncia voluntaria')
        ->and($mensual['filas'][0][6])->toBe(1);

    $causales = ($this->motor)()->exportable($this->rh, ['vista' => 'por_causales', 'desde' => '2026-10', 'hasta' => '2026-10', 'forma' => 'RE']);
    expect($causales['titulo'])->toContain('Por causales')
        ->and(array_column($causales['filas'], 0))->toBe(['RE-01']);

    $sucursal = ($this->motor)()->exportable($this->rh, ['vista' => 'por_sucursal', 'desde' => '2026-10', 'hasta' => '2026-10', 'sucursal_id' => $this->sucursal->id]);
    expect(array_column($sucursal['filas'], 0))->toBe([$this->sucursal->nombre])
        ->and($sucursal['columnas'])->toContain('Promedio mensual %');

    Excel::fake();
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.excel', ['vista' => 'por_causales', 'desde' => '2026-10', 'hasta' => '2026-10', 'forma' => 'RE']));
    Excel::assertDownloaded(sprintf('rotacion-%s.xlsx', now()->format('Y-m-d')), fn (ReporteRhExport $export): bool => true);
});

test('metas: un cambio aplica desde su vigencia y un mes pasado conserva la que tenía', function () {
    rotPlantilla($this->sucursal, '2026-09', 100);
    rotPlantilla($this->sucursal, '2026-10', 100);

    foreach (['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $fecha) {
        rotBaja($this->sucursal, 'RV-01', $fecha);
    }

    $this->actingAs($this->rh)->post(route('rh.bajas.rotacion-metas'), ['segmento' => 'sucursales', 'verde_hasta' => 2, 'ambar_hasta' => 3, 'vigente_desde' => '2026-10', 'motivo' => 'Ajuste aprobado por RH'])
        ->assertSessionHasNoErrors();

    $serie = collect(($this->motor)()->tablero($this->rh, ['vista' => 'sucursales', 'desde' => '2026-09', 'hasta' => '2026-10'])['tendencia'])->keyBy('periodo');

    expect($serie['2026-09']['indice'])->toBe(5.0)
        ->and($serie['2026-09']['semaforo'])->toBe('ambar')
        ->and($serie['2026-09']['meta']['verde_hasta'])->toBe(4.0)
        ->and($serie['2026-10']['semaforo'])->toBe('rojo')
        ->and($serie['2026-10']['meta']['verde_hasta'])->toBe(2.0);

    // Historial: la meta anterior sigue ahí y la nueva guarda quién y por qué.
    expect(RotacionMeta::query()->where('segmento', 'sucursales')->count())->toBe(2)
        ->and(RotacionMeta::query()->latest('id')->first()?->registrado_por)->toBe($this->rh->id);
});

test('metas: valores sembrados, fronteras del semáforo y validación', function () {
    $metas = app(RotacionMetaService::class);
    $mes = CarbonImmutable::create(2026, 10, 1);

    expect($metas->paraMes(SegmentoRotacion::Sucursales, $mes))->toBe(['verde_hasta' => 4.0, 'ambar_hasta' => 6.0])
        ->and($metas->paraMes(SegmentoRotacion::Corporativo, $mes))->toBe(['verde_hasta' => 1.7, 'ambar_hasta' => 2.5])
        ->and($metas->paraMes(SegmentoRotacion::Global, $mes))->toBe(['verde_hasta' => 3.0, 'ambar_hasta' => 4.5])
        ->and($metas->semaforo(SegmentoRotacion::Global, 3.0, $mes))->toBe('verde')
        ->and($metas->semaforo(SegmentoRotacion::Global, 4.5, $mes))->toBe('ambar')
        ->and($metas->semaforo(SegmentoRotacion::Global, 4.51, $mes))->toBe('rojo')
        ->and($metas->semaforo(SegmentoRotacion::Global, null, $mes))->toBeNull();

    $this->actingAs($this->rh)->post(route('rh.bajas.rotacion-metas'), ['segmento' => 'global', 'verde_hasta' => 5, 'ambar_hasta' => 4, 'vigente_desde' => '2026-11', 'motivo' => 'Ajuste de prueba'])
        ->assertSessionHasErrors('ambar_hasta');
    $this->actingAs($this->rh)->post(route('rh.bajas.rotacion-metas'), ['segmento' => 'global', 'verde_hasta' => 2, 'ambar_hasta' => 4, 'vigente_desde' => '2026-11'])
        ->assertSessionHasErrors('motivo');
});

test('permisos: sin bajas.gestionar no se editan metas ni sin tablero se consulta o exporta', function () {
    $direccion = clUsuario('direccion', ['sucursal_principal_id' => $this->sucursal->id]);
    $colaborador = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($direccion)->get(route('rh.bajas.rotacion'))->assertOk();
    $this->actingAs($direccion)->post(route('rh.bajas.rotacion-metas'), ['segmento' => 'global', 'verde_hasta' => 2, 'ambar_hasta' => 4, 'vigente_desde' => '2026-11', 'motivo' => 'Ajuste de prueba'])->assertForbidden();

    $this->actingAs($colaborador)->get(route('rh.bajas.rotacion'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('reportes.rotacion.tablero.excel'))->assertForbidden();
});

test('alcance: un gerente no ve las bajas de otra sucursal aunque pida su id', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    rotBaja($otra, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RV-01', '2026-10-03');
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);

    $datos = ($this->motor)()->tablero($gerente, ['vista' => 'por_sucursal', 'desde' => '2026-10', 'hasta' => '2026-10', 'sucursal_id' => $otra->id]);

    expect(collect($datos['sucursales'])->pluck('sucursal_id')->all())->not->toContain($otra->id)
        ->and($datos['resumen']['comprobacion']['global'])->toBe(1);
});

test('Tablero de RH y pestaña Rotación dan la misma cifra del mismo motor', function () {
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02', EstatusBaja::EnRevisionRh);

    $tablero = app(TableroRhService::class)->construir($this->rh, ['mes' => '2026-10', 'sucursal_id' => $this->sucursal->id, 'meses' => 6]);
    $pestana = ($this->motor)()->tablero($this->rh, ['vista' => 'sucursales', 'sucursal_id' => $this->sucursal->id, 'desde' => '2026-10', 'hasta' => '2026-10'])['resumen']['mes'];
    $punto = collect($tablero['turnover_monthly'])->firstWhere('mes', '2026-10');

    expect($tablero['summary']['rotacion_mes']['porcentaje'])->toBe($pestana['indice'])
        ->and($tablero['summary']['rotacion_mes']['bajas'])->toBe(1)
        ->and($punto['indice'])->toBe(2.0)
        ->and($punto['en_curso'])->toBeTrue()
        ->and($tablero['summary']['rotacion_mes']['semaforo'])->toBe($pestana['semaforo']);
});
