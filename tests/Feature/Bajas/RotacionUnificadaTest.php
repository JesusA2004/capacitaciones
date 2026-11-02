<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Enums\SegmentoRotacion;
use App\Exports\RotacionExport;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use App\Models\RotacionCarga;
use App\Models\RotacionMeta;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\BajaRegistroService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Reportes\TableroRhService;
use App\Services\Rotacion\FormulaRotacion;
use App\Services\Rotacion\RotacionAnalisisService;
use App\Services\Rotacion\RotacionMetaService;
use App\Services\Rotacion\RotacionMotorService;
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
| Corporativo + Sucursales en bajas. Motor: RotacionMotorService.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow('2026-10-15 12:00:00');
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    config(['bajas.tablero.mes_inicio_sistema' => '2026-09']);

    $this->app->instance(BrowsershotRenderer::class, new class extends BrowsershotRenderer
    {
        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            return '%PDF-1.4 prueba';
        }
    });

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

/**
 * @param  array<string, mixed>  $entrada
 * @param  list<int>|null  $sucursales
 * @return array<string, mixed>
 */
function rotAnalisis(User $usuario, array $entrada, ?array $sucursales = null): array
{
    $f = app(RotacionMotorService::class)->filtros($usuario, $entrada);
    $f = $sucursales !== null ? $f->conSucursales(collect($sucursales)) : $f;
    $a = app(RotacionAnalisisService::class);

    return ['resumen' => $a->resumen($f), 'tendencia' => $a->tendencia($f), 'causales' => $a->causales($f), 'sucursales' => $a->sucursales($f)];
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

    $mes = ($this->motor)()->mes(CarbonImmutable::create(2026, 10, 1), collect([$this->sucursal->id]));

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

    $sucursales = collect([$this->sucursal->id]);
    $agosto = ($this->motor)()->mes(CarbonImmutable::create(2026, 8, 1), $sucursales);
    $septiembre = ($this->motor)()->mes(CarbonImmutable::create(2026, 9, 1), $sucursales);
    $datos = rotAnalisis($this->rh, ['periodo' => 'rango', 'desde' => '2026-08-01', 'hasta' => '2026-10-15', 'sucursal_id' => $this->sucursal->id]);
    $serie = collect($datos['tendencia']['meses'])->keyBy('periodo');

    expect($agosto['fuente'])->toBe('excel')
        ->and($agosto['bajas'])->toBe(3)
        ->and($agosto['indice'])->toBe(6.25)
        ->and($serie['2026-08']['sin_desglose'])->toBe(2)
        ->and($septiembre['fuente'])->toBe('sistema')
        ->and($septiembre['bajas'])->toBe(1)
        // El Excel de septiembre (9) no se suma, pero el descuadre se muestra.
        ->and($serie['2026-09']['discrepancias'])->toBe(1)
        ->and($septiembre['plantilla_promedio'])->toBeGreaterThanOrEqual(48.0)
        ->and($serie['2026-10']['total'])->toBe(1)
        ->and($datos['resumen']['total'])->toBe(5);
});

test('mes sin datos y mes futuro no son 0% ni rompen; el promedio no incluye el mes en curso', function () {
    rotPlantilla($this->sucursal, '2026-09', 50);
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-09-10');
    rotBaja($this->sucursal, 'RV-01', '2026-10-10');
    rotBaja($this->sucursal, 'RV-02', '2026-10-11');

    $sucursales = collect([$this->sucursal->id]);
    $julio = ($this->motor)()->mes(CarbonImmutable::create(2026, 7, 1), $sucursales);
    $noviembre = ($this->motor)()->mes(CarbonImmutable::create(2026, 11, 1), $sucursales);
    $datos = rotAnalisis($this->rh, ['periodo' => 'rango', 'desde' => '2026-07-01', 'hasta' => '2026-11-30', 'sucursal_id' => $this->sucursal->id]);

    expect($julio['indice'])->toBeNull()
        ->and($julio['fuente'])->toBe('sin_datos')
        ->and($noviembre['indice'])->toBeNull()
        ->and($noviembre['fuente'])->toBe('sin_datos')
        ->and(collect($datos['tendencia']['meses'])->pluck('periodo')->last())->toBe('2026-10')
        ->and(collect($datos['tendencia']['meses'])->firstWhere('periodo', '2026-07')['sin_datos'])->toBeTrue()
        ->and($datos['resumen']['rotacion']['promedio_indices'])->toBe(2.0);
});

test('sin foto ni Excel, la plantilla desde el corte sale de las fechas del sistema', function () {
    Colaborador::factory()->count(10)->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2025-01-01']);

    $mes = ($this->motor)()->mes(CarbonImmutable::create(2026, 10, 1), collect([$this->sucursal->id]));

    expect($mes['fuente'])->toBe('sistema')
        ->and($mes['plantilla_promedio'])->toBeGreaterThanOrEqual(10.0);
});

test('cambio de sucursal: la baja se queda en la sucursal donde ocurrió', function () {
    $baja = rotBaja($this->sucursal, 'RV-01', '2026-10-05');
    $otra = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    Colaborador::query()->whereKey($baja->colaborador_id)->update(['sucursal_principal_id' => $otra->id]);

    $filas = collect(rotAnalisis($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10])['sucursales'])->keyBy('sucursal_id');

    expect($filas[$this->sucursal->id]['bajas'])->toBe(1)
        ->and($filas->has($otra->id))->toBeFalse();
});

test('escenario 30: Global = Corporativo + Sucursales en bajas; el índice global no es la suma', function () {
    rotPlantilla($this->sucursal, '2026-10', 100);
    rotPlantilla($this->corporativo, '2026-10', 20);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RE-01', '2026-10-03');
    rotBaja($this->corporativo, 'NR-02', '2026-10-04');

    $entrada = ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10];
    $global = rotAnalisis($this->rh, $entrada, [$this->sucursal->id, $this->corporativo->id])['resumen'];
    $corporativo = rotAnalisis($this->rh, $entrada, [$this->corporativo->id])['resumen'];
    $sucursales = rotAnalisis($this->rh, $entrada, [$this->sucursal->id])['resumen'];

    expect($global['total'])->toBe($corporativo['total'] + $sucursales['total'])
        ->and($global['total'])->toBe(3)
        ->and($global['rotacion']['indice'])->toBe(2.5)
        ->and($corporativo['rotacion']['indice'])->toBe(5.0)
        ->and($sucursales['rotacion']['indice'])->toBe(2.0);
});

test('escenario 31: la pestaña de Bajas abre el mismo módulo y Corporativo usa su propia meta', function () {
    rotPlantilla($this->corporativo, '2026-10', 100);
    rotBaja($this->corporativo, 'RV-01', '2026-10-02');
    rotBaja($this->corporativo, 'RV-01', '2026-10-03');

    $this->actingAs($this->rh)->get(route('rh.bajas.rotacion', ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reportes/Rotacion/Index')->has('metas.vigentes', 3)->where('pestanas.rotacion', true));

    // 2% es verde contra la meta global (3%) y ámbar contra la de Corporativo (1.7%).
    $mes = ($this->motor)()->serieParaTablero(collect([$this->corporativo->id]), CarbonImmutable::create(2026, 10, 1), 1)[0];
    expect($mes['indice'])->toBe(2.0)->and($mes['semaforo'])->toBe('ambar');
});

test('por sucursal: semáforo del promedio mensual, Corporativo marcado y su serie mensual', function () {
    rotPlantilla($this->sucursal, '2026-09', 50);
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotPlantilla($this->corporativo, '2026-10', 10);
    rotBaja($this->sucursal, 'RV-01', '2026-09-02');
    rotBaja($this->sucursal, 'RE-01', '2026-10-02');
    rotBaja($this->sucursal, 'RV-02', '2026-10-03');
    rotBaja($this->sucursal, 'RV-03', '2026-10-04');
    rotBaja($this->corporativo, 'RV-01', '2026-10-05');

    $filas = collect(rotAnalisis($this->rh, ['periodo' => 'rango', 'desde' => '2026-09-01', 'hasta' => '2026-10-15'])['sucursales'])->keyBy('sucursal_id');
    $fila = $filas[$this->sucursal->id];

    expect($filas[$this->corporativo->id]['es_corporativo'])->toBeTrue()
        ->and($fila['bajas'])->toBe(4)
        ->and($fila['participacion'])->toBe(80.0)
        // Septiembre cerrado: 1/50 = 2%; el mes en curso no entra al promedio.
        ->and($fila['promedio_indices'])->toBe(2.0)
        ->and($fila['semaforo'])->toBe('verde')
        ->and($fila['serie'])->toBe([1, 3]);
});

test('por causales: ranking homologado con cantidad y porcentaje que respeta los filtros', function () {
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RV-01', '2026-10-03');
    rotBaja($this->sucursal, 'RE-03', '2026-10-04');

    $datos = rotAnalisis($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10]);
    expect($datos['causales']['causales'][0]['clave'])->toBe('oferta_laboral')
        ->and($datos['causales']['causales'][0]['bajas'])->toBe(2)
        ->and($datos['causales']['causales'][0]['porcentaje'])->toBe(66.7);

    $involuntarias = rotAnalisis($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10, 'clasificacion' => 'involuntaria']);
    expect(collect($involuntarias['causales']['causales'])->pluck('clave')->all())->toBe(['probidad_conducta']);
});

test('voluntaria contra involuntaria: el Excel aporta lo clasificado y el resto es sin clasificar', function () {
    rotExcel($this, $this->sucursal, 2026, 8, 5, 50, 46, voluntarias: 2);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'NR-02', '2026-10-03');
    rotBaja($this->sucursal, 'OT-03', '2026-10-04');

    $r = rotAnalisis($this->rh, ['periodo' => 'rango', 'desde' => '2026-08-01', 'hasta' => '2026-10-15'])['resumen'];

    expect($r['total'])->toBe(8)
        ->and($r['voluntarias']['bajas'])->toBe(3)
        ->and($r['involuntarias']['bajas'])->toBe(2)
        ->and($r['sin_clasificar']['bajas'])->toBe(3)
        ->and($r['sin_clasificar']['sin_desglose'])->toBe(5)
        ->and($r['identidad'])->toBeTrue();
});

test('rotación temprana: antes de seis meses sobre bajas con fecha de ingreso válida', function () {
    rotBaja($this->sucursal, 'RV-01', '2026-10-02', ingreso: '2026-09-01');
    rotBaja($this->sucursal, 'RV-02', '2026-10-03', ingreso: '2024-01-01');

    $t = rotAnalisis($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10])['resumen']['temprana'];

    expect($t)->toBe(['bajas' => 1, 'base' => 2, 'excluidas' => 0, 'porcentaje' => 50.0]);
});

test('escenario 32: las exportaciones usan los mismos filtros y cifras', function () {
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RE-01', '2026-10-03');
    $consulta = ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10, 'clasificacion' => 'voluntaria'];

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.excel', $consulta))->assertOk()->assertDownload();
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.pdf', $consulta))->assertOk();
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.pdf-detallado', $consulta))->assertOk();

    Excel::fake();
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.excel', $consulta));
    Excel::assertDownloaded(sprintf('rotacion-%s.xlsx', now()->format('Y-m-d')), function (RotacionExport $export): bool {
        $resumen = $export->sheets()[0]->array();

        return collect($resumen)->firstWhere(0, 'Total de bajas')[1] === 2;
    });
});

test('metas: un cambio aplica desde su vigencia y un mes pasado conserva la que tenía', function () {
    rotPlantilla($this->sucursal, '2026-09', 100);
    rotPlantilla($this->sucursal, '2026-10', 100);

    foreach (['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $fecha) {
        rotBaja($this->sucursal, 'RV-01', $fecha);
    }

    $this->actingAs($this->rh)->post(route('rh.bajas.rotacion-metas'), ['segmento' => 'sucursales', 'verde_hasta' => 2, 'ambar_hasta' => 3, 'vigente_desde' => '2026-10', 'motivo' => 'Ajuste aprobado por RH'])
        ->assertSessionHasNoErrors();

    $serie = collect(($this->motor)()->serieParaTablero(collect([$this->sucursal->id]), CarbonImmutable::create(2026, 10, 1), 2))->keyBy('periodo');

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
    $this->actingAs($colaborador)->get(route('reportes.rotacion.tablero.pdf'))->assertForbidden();
});

test('alcance: un gerente no ve las bajas de otra sucursal aunque pida su id', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    rotBaja($otra, 'RV-01', '2026-10-02');
    rotBaja($this->sucursal, 'RV-01', '2026-10-03');
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);

    $datos = rotAnalisis($gerente, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10, 'sucursal_id' => $otra->id]);

    expect(collect($datos['sucursales'])->pluck('sucursal_id')->all())->not->toContain($otra->id)
        ->and($datos['resumen']['total'])->toBe(1);
});

test('Tablero de RH y módulo de Rotación dan la misma cifra del mismo motor', function () {
    rotPlantilla($this->sucursal, '2026-10', 50);
    rotBaja($this->sucursal, 'RV-01', '2026-10-02', EstatusBaja::EnRevisionRh);

    $tablero = app(TableroRhService::class)->construir($this->rh, ['mes' => '2026-10', 'sucursal_id' => $this->sucursal->id, 'meses' => 6]);
    $modulo = collect(rotAnalisis($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10, 'sucursal_id' => $this->sucursal->id])['tendencia']['meses'])->firstWhere('periodo', '2026-10');
    $punto = collect($tablero['turnover_monthly'])->firstWhere('mes', '2026-10');

    expect($tablero['summary']['rotacion_mes']['porcentaje'])->toBe($modulo['indice'])
        ->and($tablero['summary']['rotacion_mes']['bajas'])->toBe($modulo['total'])
        ->and($punto['indice'])->toBe(2.0)
        ->and($punto['en_curso'])->toBeTrue();
});
