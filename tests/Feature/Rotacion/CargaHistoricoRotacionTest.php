<?php

use App\Enums\RangoAntiguedad;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\ImportacionExcel;
use App\Models\RotacionCarga;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Rotacion\BajasHistoricasImportService;
use App\Services\Rotacion\RotacionAnalisisService;
use App\Services\Rotacion\RotacionMotorService;
use Carbon\CarbonImmutable;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;

/*
|--------------------------------------------------------------------------
| Carga del histórico individual de bajas desde un archivo versionado
| (rotacion:cargar-historico, docs/ROTACION.md): lectura del CSV con
| antigüedad en días o años, unidades externas sin sucursal en People,
| idempotencia, auditoría y la regla de conciliación con el Excel de
| totales (un mes con histórico individual usa el detalle; el Excel solo se
| compara).
|--------------------------------------------------------------------------
*/

const CHR_FIXTURE = __DIR__.'/../../Fixtures/rotacion/bajas_historicas_prueba.csv';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12));
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    config(['bajas.tablero.mes_inicio_sistema' => '2026-10']);

    $estructura = clEstructura();
    $this->cuernavaca = $estructura['sucursal'];
    $this->miacatlan = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Miacatlan']);
    $this->admin = clUsuario('super_admin');
});

function chrExcel(Sucursal $sucursal, int $mes, int $bajas): RotacionPeriodo
{
    $carga = RotacionCarga::query()->first() ?? RotacionCarga::query()->create(['nombre_archivo' => 'rotacion.xlsx', 'archivo_ruta' => 'rotacion/x.xlsx', 'archivo_hash' => str_repeat('b', 64), 'estado' => 'aplicada', 'subido_por' => User::factory()->create()->id]);

    return RotacionPeriodo::query()->create([
        'carga_id' => $carga->id, 'anio' => 2026, 'mes' => $mes, 'sucursal_id' => $sucursal->id, 'sucursal_excel' => mb_strtoupper($sucursal->nombre),
        'plantilla_inicial' => 20, 'altas' => 0, 'bajas' => $bajas, 'bajas_voluntarias' => 0, 'bajas_involuntarias' => 0, 'plantilla_final' => 20,
    ]);
}

/** @param  array<string, mixed>  $entrada */
function chrDatos(User $usuario, array $entrada = []): array
{
    $f = app(RotacionMotorService::class)->filtros($usuario, ['periodo' => 'rango', 'desde' => '2026-01-01', 'hasta' => '2026-10-23', ...$entrada]);
    $analisis = app(RotacionAnalisisService::class);

    return ['f' => $f, 'resumen' => $analisis->resumen($f), 'tendencia' => $analisis->tendencia($f), 'antiguedad' => $analisis->antiguedad($f), 'sucursales' => $analisis->sucursales($f), 'calidad' => $analisis->calidad($f)];
}

function chrEsperado(): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'esperado-').'.json';
    file_put_contents($ruta, (string) json_encode([
        'desde' => '2026-01-01',
        'hasta' => '2026-10-31',
        'total' => 6,
        'por_ubicacion' => ['Cuernavaca' => 3, 'Miacatlán' => 1, 'Itzamarketing' => 1, 'Zen Japonés' => 1],
    ]));

    return $ruta;
}

test('lee el CSV: antigüedad en días da el ingreso exacto, en años es aproximada y una unidad externa no necesita sucursal', function () {
    $filas = collect(app(BajasHistoricasImportService::class)->leer(CHR_FIXTURE)['filas'])->keyBy('fila');

    expect($filas->pluck('estado')->unique()->values()->all())->toBe(['nuevo'])
        // «30 d» antes del 4 de enero.
        ->and($filas[2]['fecha_ingreso'])->toBe('2025-12-05')
        ->and($filas[2]['antiguedad_dias'])->toBeNull()
        // «Miacatlán» del archivo = «Miacatlan» del catálogo.
        ->and($filas[3]['sucursal_id'])->toBe($this->miacatlan->id)
        ->and($filas[3]['fecha_ingreso'])->toBeNull()
        ->and($filas[3]['antiguedad_dias'])->toBe(913)
        ->and($filas[3]['rango_antiguedad'])->toBe(RangoAntiguedad::De1A3Anios->value)
        ->and($filas[4]['sucursal_id'])->toBeNull()
        ->and($filas[4]['unidad_externa'])->toBe('Itzamarketing')
        ->and($filas[4]['rango_antiguedad'])->toBe(RangoAntiguedad::Mas3Anios->value)
        // «1.0 a» no dice si ya cumplía 12 meses: sin rango y con aviso.
        ->and($filas[5]['unidad_externa'])->toBe('Zen Japonés')
        ->and($filas[5]['rango_antiguedad'])->toBeNull()
        ->and(implode(' ', $filas[5]['avisos']))->toContain('no se sabe si cruzó el límite')
        ->and($filas[6]['fecha_ingreso'])->toBe('2026-02-01')
        ->and($filas[7]['antiguedad_dias'])->toBeNull()
        ->and($filas[7]['rango_antiguedad'])->toBeNull()
        ->and(Sucursal::query()->whereIn('nombre', ['Itzamarketing', 'Zen Japonés'])->exists())->toBeFalse();
});

test('una sucursal que no está en el catálogo ni es unidad externa confirmada sigue siendo error', function () {
    $ruta = tempnam(sys_get_temp_dir(), 'bajas-').'.csv';
    file_put_contents($ruta, "Nombre completo,Sucursal,Último día laborado\n\"Fulano Prueba\",Solariega,2026-02-01\n");

    $fila = app(BajasHistoricasImportService::class)->leer($ruta)['filas'][0];

    expect($fila['estado'])->toBe('error')
        ->and($fila['errores'][0])->toContain('Solariega');
});

test('el comando sin --aplicar no escribe nada; con --aplicar carga auditado y una segunda corrida no cambia nada', function () {
    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--esperado' => chrEsperado()])
        ->expectsOutputToContain('Vista previa: no se escribió nada')
        ->assertSuccessful();

    expect(BajaHistorica::query()->count())->toBe(0)
        ->and(ImportacionExcel::query()->count())->toBe(0);

    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--esperado' => chrEsperado(), '--aplicar' => true, '--usuario' => $this->admin->email])
        ->expectsOutputToContain('Cargadas 6 bajas históricas')
        ->expectsOutputToContain('Todos los indicadores cuadran')
        ->assertSuccessful();

    $importacion = ImportacionExcel::query()->sole();
    expect(BajaHistorica::query()->count())->toBe(6)
        ->and($importacion->tipo)->toBe(BajasHistoricasImportService::TIPO)
        ->and($importacion->estado)->toBe('aplicado')
        ->and($importacion->aplicado_por)->toBe($this->admin->id)
        ->and($importacion->metadatos['origen'] ?? null)->toBe('comando rotacion:cargar-historico')
        ->and(BajaHistorica::query()->where('unidad_externa', 'Itzamarketing')->value('sucursal_id'))->toBeNull()
        ->and(BajaHistorica::query()->where('nombre', 'Carla Prueba Tres')->value('nota'))->toContain('Rango provisional de prueba');

    $this->artisan('rotacion:cargar-historico-2026', ['archivo' => CHR_FIXTURE, '--aplicar' => true])
        ->expectsOutputToContain('Sin cambios')
        ->assertSuccessful();

    expect(BajaHistorica::query()->count())->toBe(6)
        ->and(ImportacionExcel::query()->count())->toBe(1);
});

test('en producción la carga exige --force', function () {
    $this->app['env'] = 'production';

    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--aplicar' => true])
        ->expectsOutputToContain('--force')
        ->assertFailed();

    expect(BajaHistorica::query()->count())->toBe(0);

    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--aplicar' => true, '--force' => true])->assertSuccessful();

    expect(BajaHistorica::query()->count())->toBe(6);
});

test('conciliación: en un mes con histórico individual manda el detalle y el Excel solo se informa; sin detalle, el Excel sigue contando', function () {
    chrExcel($this->cuernavaca, 1, 5);
    chrExcel($this->cuernavaca, 4, 3);
    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--aplicar' => true])->assertSuccessful();

    $d = chrDatos($this->admin);
    $meses = collect($d['tendencia']['meses'])->keyBy('periodo');

    expect($meses['2026-01']['total'])->toBe(2)
        ->and($meses['2026-01']['sin_desglose'])->toBe(0)
        ->and($meses['2026-01']['discrepancias'])->toBe(1)
        ->and(collect($d['calidad']['discrepancias'])->firstWhere('mes', '2026-01'))->toMatchArray(['sucursal' => 'Cuernavaca', 'agregado' => 5, 'individuales' => 1, 'diferencia' => 4])
        // Abril no tiene histórico individual: el total del Excel cuenta.
        ->and($meses['2026-04']['total'])->toBe(3)
        ->and($meses['2026-04']['sin_desglose'])->toBe(3)
        ->and($d['resumen']['total'])->toBe(9);
});

test('unidades externas: cuentan para el alcance global, sin índice ni semáforo; un gerente o un filtro de sucursal no las ve', function () {
    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--aplicar' => true])->assertSuccessful();

    $global = chrDatos($this->admin);
    $externa = collect($global['sucursales'])->firstWhere('sucursal', 'Itzamarketing');
    $zen = collect($global['sucursales'])->firstWhere('sucursal', 'Zen Japonés');

    expect($global['resumen']['total'])->toBe(6)
        ->and($externa)->toMatchArray(['bajas' => 1, 'empresa' => 'Itzamarketing', 'unidad_externa' => true, 'indice' => null, 'semaforo' => null])
        ->and($externa['sucursal_id'])->toBeLessThan(0)
        ->and($zen['empresa'])->toBe('Lerom (restaurantes)')
        ->and(chrDatos($this->admin, ['sucursal_id' => $this->cuernavaca->id])['resumen']['total'])->toBe(3);

    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->cuernavaca->id]);
    $acotado = chrDatos($gerente);

    expect(collect($acotado['sucursales'])->pluck('sucursal')->all())->not->toContain('Itzamarketing')
        ->and($acotado['resumen']['total'])->toBe(3);
});

test('antigüedad aproximada: cuenta en su rango sin inventar la fecha de ingreso y la fecha real, si se completa, manda', function () {
    $this->artisan('rotacion:cargar-historico', ['archivo' => CHR_FIXTURE, '--aplicar' => true])->assertSuccessful();

    $d = chrDatos($this->admin);
    $rangos = collect($d['antiguedad']['rangos'])->pluck('bajas', 'clave');

    expect($rangos[RangoAntiguedad::Menos3Meses->value])->toBe(2)
        ->and($rangos[RangoAntiguedad::De1A3Anios->value])->toBe(1)
        ->and($rangos[RangoAntiguedad::Mas3Anios->value])->toBe(1)
        ->and($rangos[RangoAntiguedad::SinFecha->value])->toBe(2)
        ->and($d['antiguedad']['base'])->toBe(4)
        ->and($d['calidad']['sin_fecha_ingreso'])->toBe(2)
        ->and($d['calidad']['antiguedad_aproximada'])->toBe(2)
        ->and(BajaHistorica::query()->where('nombre', 'Beto Prueba Dos')->value('fecha_ingreso'))->toBeNull();

    // RH completa la fecha real: deja de ser aproximada.
    BajaHistorica::query()->where('nombre', 'Beto Prueba Dos')->update(['fecha_ingreso' => '2025-11-01']);
    $despues = collect(chrDatos($this->admin)['antiguedad']['rangos'])->pluck('bajas', 'clave');

    expect($despues[RangoAntiguedad::De1A3Anios->value])->toBe(0)
        ->and($despues[RangoAntiguedad::Menos3Meses->value])->toBe(3);
});

test('rangos de años redondeados: solo se deducen si no cruzan un límite', function () {
    expect(RangoAntiguedad::deAniosRedondeados(2.5))->toBe(RangoAntiguedad::De1A3Anios)
        ->and(RangoAntiguedad::deAniosRedondeados(3.1))->toBe(RangoAntiguedad::Mas3Anios)
        ->and(RangoAntiguedad::deAniosRedondeados(0.9))->toBe(RangoAntiguedad::De6A12Meses)
        ->and(RangoAntiguedad::deAniosRedondeados(1.0))->toBeNull()
        ->and(RangoAntiguedad::deAniosRedondeados(3.0))->toBeNull()
        ->and(RangoAntiguedad::desdeTexto('De 1 a menos de 3 años'))->toBe(RangoAntiguedad::De1A3Anios)
        ->and(RangoAntiguedad::desdeTexto('mas_3_anios'))->toBe(RangoAntiguedad::Mas3Anios)
        ->and(CausalAnalitica::query()->whereIn('clave', ['oferta_laboral', 'bajo_desempeno', 'abandono_faltas', 'personales_familiares', 'probidad_conducta', 'sin_causal', 'renuncia_voluntaria', 'estructural', 'no_le_gusto', 'salud', 'desfases_cartera'])->count())->toBe(11);
});
