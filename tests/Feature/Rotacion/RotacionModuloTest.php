<?php

use App\Enums\ClasificacionSalida;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Enums\RangoAntiguedad;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\ImportacionExcel;
use App\Models\PlantillaMensual;
use App\Models\RotacionCarga;
use App\Models\RotacionCorreccion;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\BajaRegistroService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Rotacion\BajasHistoricasImportService;
use App\Services\Rotacion\RotacionAnalisisService;
use App\Services\Rotacion\RotacionMotorService;
use Carbon\CarbonImmutable;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| Rotación de personal (docs/ROTACION.md): motor único de eventos, histórico
| individual y agregado, conciliación sin doble conteo, correcciones
| auditadas, permisos por alcance y exportaciones con las mismas cifras.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::create(2026, 10, 23, 12));
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    config(['bajas.tablero.mes_inicio_sistema' => '2026-10']);

    $this->app->instance(BrowsershotRenderer::class, new class extends BrowsershotRenderer
    {
        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            return '%PDF-1.4 prueba';
        }
    });

    $estructura = clEstructura();
    $this->cuernavaca = $estructura['sucursal'];
    $this->tula = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Tula']);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->cuernavaca->id]);
});

function rmCausal(string $clave): CausalAnalitica
{
    return CausalAnalitica::query()->where('clave', $clave)->firstOrFail();
}

/** @param  array<string, mixed>  $extra */
function rmHistorica(Sucursal $sucursal, string $fechaBaja, ?string $ingreso = '2025-01-01', ?string $causal = 'oferta_laboral', array $extra = []): BajaHistorica
{
    $nombre = $extra['nombre'] ?? fake()->unique()->name();

    return BajaHistorica::query()->create([
        'llave' => BajaHistorica::llaveDe($nombre, null, $fechaBaja, $sucursal->id),
        'nombre' => $nombre,
        'sucursal_id' => $sucursal->id,
        'empresa_id' => $sucursal->empresa_id,
        'fecha_ingreso' => $ingreso,
        'fecha_baja' => $fechaBaja,
        'motivo_original' => 'Motivo capturado por RH',
        'causal_analitica_id' => $causal !== null ? rmCausal($causal)->id : null,
        'estado' => BajaHistorica::VALIDA,
        ...$extra,
    ]);
}

function rmBaja(Sucursal $sucursal, string $clave, string $fecha, string $ingreso = '2026-01-01', EstatusBaja $estatus = EstatusBaja::Cerrada, bool $rotacion = true): Baja
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
        'cerrado_en' => now(),
    ], null);
    $baja->save();

    return $baja;
}

function rmExcel(Sucursal $sucursal, int $anio, int $mes, int $bajas, int $voluntarias = 0, int $involuntarias = 0, int $plantilla = 20): RotacionPeriodo
{
    $carga = RotacionCarga::query()->first() ?? RotacionCarga::query()->create(['nombre_archivo' => 'rotacion.xlsx', 'archivo_ruta' => 'rotacion/x.xlsx', 'archivo_hash' => str_repeat('a', 64), 'estado' => 'aplicada', 'subido_por' => User::factory()->create()->id]);

    return RotacionPeriodo::query()->create([
        'rotacion_carga_id' => $carga->id, 'anio' => $anio, 'mes' => $mes, 'sucursal_id' => $sucursal->id,
        'plantilla_inicial' => $plantilla, 'altas' => 0, 'bajas' => $bajas, 'bajas_voluntarias' => $voluntarias, 'bajas_involuntarias' => $involuntarias, 'plantilla_final' => $plantilla,
    ]);
}

/** @param  array<string, mixed>  $entrada */
function rmDatos(User $usuario, array $entrada = []): array
{
    $motor = app(RotacionMotorService::class);
    $f = $motor->filtros($usuario, $entrada);
    $analisis = app(RotacionAnalisisService::class);

    return ['f' => $f, 'resumen' => $analisis->resumen($f), 'tendencia' => $analisis->tendencia($f), 'causales' => $analisis->causales($f), 'antiguedad' => $analisis->antiguedad($f), 'sucursales' => $analisis->sucursales($f), 'matriz' => $analisis->matriz($f), 'calidad' => $analisis->calidad($f)];
}

test('fórmulas: índice = bajas ÷ plantilla promedio × 100 y plantilla desconocida es null, nunca 0', function () {
    PlantillaMensual::query()->create(['periodo' => '2026-10', 'anio' => 2026, 'mes' => 10, 'sucursal_id' => $this->cuernavaca->id, 'activos_inicio' => 40, 'activos_cierre' => 38, 'plantilla_promedio' => '39.00', 'fuente' => 'foto']);
    rmBaja($this->cuernavaca, 'RV-01', '2026-10-05');
    rmBaja($this->cuernavaca, 'RE-01', '2026-10-10');

    $d = rmDatos($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 10, 'sucursal_id' => $this->cuernavaca->id]);
    $octubre = collect($d['tendencia']['meses'])->firstWhere('periodo', '2026-10');

    expect($octubre['indice'])->toBe(round(2 * 100 / 39, 2))
        ->and($d['resumen']['rotacion']['indice'])->toBe(round(2 * 100 / 39, 2));

    $sinPlantilla = rmDatos($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 3, 'sucursal_id' => $this->tula->id]);
    expect($sinPlantilla['resumen']['rotacion']['indice'])->toBeNull();
});

test('agregación mensual: meses con datos, sin datos (no cero) y nunca meses futuros; identifica el mes con más bajas', function () {
    rmExcel($this->cuernavaca, 2026, 1, 3, 2, 1);
    rmHistorica($this->cuernavaca, '2026-09-03');
    rmHistorica($this->cuernavaca, '2026-09-10');
    rmHistorica($this->cuernavaca, '2026-09-20');
    rmHistorica($this->tula, '2026-09-25');
    rmBaja($this->cuernavaca, 'RV-05', '2026-10-02');

    $d = rmDatos($this->rh);
    $meses = collect($d['tendencia']['meses'])->keyBy('periodo');

    expect($meses->keys()->last())->toBe('2026-10')
        ->and($meses['2026-01']['total'])->toBe(3)
        ->and($meses['2026-02']['sin_datos'])->toBeTrue()
        ->and($meses['2026-02']['indice'])->toBeNull()
        ->and($meses['2026-09']['total'])->toBe(4)
        ->and($meses['2026-10']['corte_parcial'])->toBeTrue()
        ->and($d['tendencia']['mes_mayor'])->toBe('2026-09')
        ->and($d['resumen']['total'])->toBe(8)
        // Enero (Excel), septiembre y octubre: los meses sin ninguna fuente no se cuentan.
        ->and($d['resumen']['meses'])->toBe(3)
        ->and($d['resumen']['promedio_mensual'])->toBe(round(8 / 3, 1));
});

test('sucursales y causales: agrupación, participación y clasificación homologada', function () {
    rmHistorica($this->cuernavaca, '2026-08-01', causal: 'oferta_laboral');
    rmHistorica($this->cuernavaca, '2026-08-02', causal: 'oferta_laboral');
    rmHistorica($this->tula, '2026-08-03', causal: 'probidad_conducta');
    rmBaja($this->tula, 'RE-01', '2026-10-01');

    $d = rmDatos($this->rh);
    $causales = collect($d['causales']['causales'])->keyBy('clave');
    $sucursales = collect($d['sucursales'])->keyBy('sucursal');

    expect($causales['oferta_laboral']['bajas'])->toBe(2)
        ->and($causales['oferta_laboral']['porcentaje'])->toBe(50.0)
        // RE-01 (rescisión en Bajas) se homologa a abandono: voluntaria SOLO para la estadística.
        ->and($causales['abandono_faltas']['clasificacion'])->toBe('voluntaria')
        ->and($d['resumen']['voluntarias']['bajas'])->toBe(3)
        ->and($d['resumen']['involuntarias']['bajas'])->toBe(1)
        ->and($sucursales['Cuernavaca']['bajas'])->toBe(2)
        ->and($sucursales['Tula']['participacion'])->toBe(50.0)
        ->and($sucursales['Tula']['causal_principal'])->not->toBeNull()
        ->and($d['matriz']['total'])->toBe(4);
});

test('sin clasificar: total = voluntarias + involuntarias + sin clasificar', function () {
    rmHistorica($this->cuernavaca, '2026-07-01', causal: null);
    rmHistorica($this->cuernavaca, '2026-07-02', causal: 'sin_causal');
    rmHistorica($this->cuernavaca, '2026-07-03', causal: 'bajo_desempeno');
    rmExcel($this->tula, 2026, 6, 4, 1, 1);

    $r = rmDatos($this->rh)['resumen'];

    expect($r['total'])->toBe(7)
        ->and($r['sin_clasificar']['bajas'])->toBe(4)
        ->and($r['sin_clasificar']['sin_desglose'])->toBe(4)
        ->and($r['identidad'])->toBeTrue()
        ->and($r['voluntarias']['bajas'] + $r['involuntarias']['bajas'] + $r['sin_clasificar']['bajas'])->toBe($r['total']);
});

test('histórico agregado sin detalle: cuenta en el total pero un filtro de causal no da un cero engañoso', function () {
    rmExcel($this->cuernavaca, 2026, 3, 5, 3, 2);

    $base = rmDatos($this->rh);
    $filtrado = rmDatos($this->rh, ['causal_analitica_id' => rmCausal('oferta_laboral')->id]);

    expect($base['resumen']['total'])->toBe(5)
        ->and($base['causales']['causales'])->toBe([])
        ->and($base['causales']['sin_desglose'])->toBe(5)
        ->and($filtrado['resumen']['total'])->toBe(0)
        ->and($filtrado['resumen']['no_desglosables'])->toBe(5)
        ->and($filtrado['resumen']['rotacion']['indice'])->toBeNull();
});

test('conciliación: el Excel con más bajas que el detalle cuenta la diferencia como sin desglose y marca el descuadre', function () {
    rmExcel($this->cuernavaca, 2026, 4, 5);
    rmHistorica($this->cuernavaca, '2026-04-02');
    rmHistorica($this->cuernavaca, '2026-04-12');
    rmHistorica($this->cuernavaca, '2026-04-22');

    $d = rmDatos($this->rh);
    $abril = collect($d['tendencia']['meses'])->firstWhere('periodo', '2026-04');

    expect($abril['total'])->toBe(5)
        ->and($abril['sin_desglose'])->toBe(2)
        ->and($abril['discrepancias'])->toBe(1)
        ->and($d['calidad']['discrepancias'][0])->toMatchArray(['mes' => '2026-04', 'agregado' => 5, 'individuales' => 3, 'diferencia' => 2]);
});

test('sin doble conteo: la histórica ligada a una baja del sistema no cuenta y un reingreso son dos eventos', function () {
    $baja = rmBaja($this->cuernavaca, 'RV-01', '2026-10-06');
    rmHistorica($this->cuernavaca, '2026-10-06', extra: ['nombre' => $baja->nombre_colaborador, 'baja_id' => $baja->id, 'estado' => BajaHistorica::DUPLICADA]);
    // Misma persona: salió en marzo, reingresó y volvió a salir en agosto.
    rmHistorica($this->tula, '2026-03-10', '2026-01-02', extra: ['nombre' => 'Ana Reingreso']);
    rmHistorica($this->tula, '2026-08-10', '2026-05-01', extra: ['nombre' => 'Ana Reingreso']);

    $d = rmDatos($this->rh);

    expect($d['resumen']['total'])->toBe(3)
        // La antigüedad es la de cada relación laboral.
        ->and(collect(app(RotacionMotorService::class)->eventos($d['f']))->where('nombre', 'Ana Reingreso')->pluck('rango')->map->value->sort()->values()->all())
        ->toBe([RangoAntiguedad::Menos3Meses->value, RangoAntiguedad::De3A6Meses->value]);
});

test('bajas del sistema: «No procede», movimientos internos y últimos días futuros no cuentan', function () {
    rmBaja($this->cuernavaca, 'RV-01', '2026-10-05');
    rmBaja($this->cuernavaca, 'RV-01', '2026-10-06', estatus: EstatusBaja::Cancelada);
    rmBaja($this->cuernavaca, 'RV-01', '2026-10-07', rotacion: false);
    rmBaja($this->cuernavaca, 'RV-01', '2026-10-30');

    expect(rmDatos($this->rh)['resumen']['total'])->toBe(1)
        ->and(app(TableroRotacionService::class)->serieParaTablero(collect([$this->cuernavaca->id]), CarbonImmutable::create(2026, 10, 1), 1)[0]['bajas'])->toBe(1);
});

test('antigüedad: rangos sin traslapes, mediana, temprana sobre fechas válidas y fechas inconsistentes', function () {
    expect(RangoAntiguedad::de(CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-04-14')))->toBe(RangoAntiguedad::Menos3Meses)
        ->and(RangoAntiguedad::de(CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-04-15')))->toBe(RangoAntiguedad::De3A6Meses)
        ->and(RangoAntiguedad::de(CarbonImmutable::parse('2025-01-15'), CarbonImmutable::parse('2026-01-15')))->toBe(RangoAntiguedad::De1A3Anios)
        ->and(RangoAntiguedad::de(CarbonImmutable::parse('2023-01-15'), CarbonImmutable::parse('2026-01-15')))->toBe(RangoAntiguedad::Mas3Anios)
        ->and(RangoAntiguedad::de(null, CarbonImmutable::parse('2026-01-15')))->toBe(RangoAntiguedad::SinFecha);

    rmHistorica($this->cuernavaca, '2026-05-01', '2026-04-01');
    rmHistorica($this->cuernavaca, '2026-05-02', '2026-01-02');
    rmHistorica($this->cuernavaca, '2026-05-03', '2025-01-01');
    rmHistorica($this->cuernavaca, '2026-05-04', null);
    rmHistorica($this->cuernavaca, '2026-05-05', '2026-06-01');

    $d = rmDatos($this->rh);

    expect($d['resumen']['temprana'])->toMatchArray(['bajas' => 2, 'base' => 3, 'excluidas' => 2])
        ->and($d['antiguedad']['inconsistentes'])->toBe(1)
        ->and($d['antiguedad']['mediana_dias'])->toBe(120)
        ->and($d['calidad']['fecha_inconsistente'])->toBe(1)
        ->and($d['calidad']['sin_fecha_ingreso'])->toBe(1);
});

test('filtros combinados de sucursal, causal y antigüedad', function () {
    rmHistorica($this->cuernavaca, '2026-06-01', '2026-05-01', 'oferta_laboral');
    rmHistorica($this->cuernavaca, '2026-06-02', '2024-01-01', 'oferta_laboral');
    rmHistorica($this->tula, '2026-06-03', '2026-05-01', 'oferta_laboral');

    $d = rmDatos($this->rh, ['sucursal_id' => $this->cuernavaca->id, 'causal_analitica_id' => rmCausal('oferta_laboral')->id, 'antiguedad' => RangoAntiguedad::Menos3Meses->value]);

    expect($d['resumen']['total'])->toBe(1)->and($d['f']->conDetalle())->toBeTrue();
});

test('corregir la causal deja bitácora con antes, después y comentario y actualiza la estadística', function () {
    $historica = rmHistorica($this->cuernavaca, '2026-07-01', causal: null);
    $oferta = rmCausal('oferta_laboral');

    $this->actingAs($this->rh)->post(route('reportes.rotacion.tablero.correcciones.causal'), ['fuente' => 'historico', 'id' => $historica->id, 'causal_analitica_id' => $oferta->id, 'comentario' => ''])
        ->assertSessionHasErrors('comentario');

    $this->actingAs($this->rh)->post(route('reportes.rotacion.tablero.correcciones.causal'), ['fuente' => 'historico', 'id' => $historica->id, 'causal_analitica_id' => $oferta->id, 'comentario' => 'Confirmado con el gerente'])
        ->assertSessionHasNoErrors();

    $bitacora = RotacionCorreccion::query()->sole();

    expect($historica->fresh()->motivo_original)->toBe('Motivo capturado por RH')
        ->and($bitacora->antes)->toBe('Sin causal registrada')
        ->and($bitacora->despues)->toBe($oferta->nombre)
        ->and($bitacora->comentario)->toBe('Confirmado con el gerente')
        ->and($bitacora->usuario_id)->toBe($this->rh->id)
        ->and(rmDatos($this->rh)['resumen']['voluntarias']['bajas'])->toBe(1);

    $baja = rmBaja($this->cuernavaca, 'RV-01', '2026-10-03');
    $this->actingAs($this->rh)->post(route('reportes.rotacion.tablero.correcciones.causal'), ['fuente' => 'sistema', 'id' => $baja->id, 'causal_analitica_id' => rmCausal('salud')->id, 'comentario' => 'Se fue por salud'])->assertSessionHasNoErrors();

    // La causal administrativa de Bajas no cambia.
    expect($baja->fresh()->causal_rotacion_id)->toBe(CausalRotacion::query()->where('clave', 'RV-01')->value('id'))
        ->and($baja->fresh()->causal_analitica_id)->toBe(rmCausal('salud')->id);
});

test('completar la fecha de ingreso de una histórica, nunca posterior a la baja', function () {
    $historica = rmHistorica($this->cuernavaca, '2026-07-01', null);

    $this->actingAs($this->rh)->post(route('reportes.rotacion.tablero.correcciones.fecha'), ['id' => $historica->id, 'fecha_ingreso' => '2026-08-01', 'comentario' => 'Dato del expediente físico'])->assertSessionHasErrors('fecha_ingreso');
    $this->actingAs($this->rh)->post(route('reportes.rotacion.tablero.correcciones.fecha'), ['id' => $historica->id, 'fecha_ingreso' => '2026-03-01', 'comentario' => 'Dato del expediente físico'])->assertSessionHasNoErrors();

    expect($historica->fresh()->fecha_ingreso->toDateString())->toBe('2026-03-01');
});

test('permisos: alcance por sucursal, sin corregir sin bajas.gestionar y sin datos fuera del alcance', function () {
    rmHistorica($this->cuernavaca, '2026-05-01');
    $ajena = rmHistorica($this->tula, '2026-05-02');
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->cuernavaca->id]);
    $gerente->givePermissionTo('bajas.tablero.ver');

    $visibles = app(RotacionMotorService::class)->sucursalesVisibles($gerente);
    expect($visibles->contains($this->tula->id))->toBeFalse();

    // Pedir la sucursal ajena por URL no filtra datos ajenos: se ignora.
    expect(rmDatos($gerente, ['sucursal_id' => $this->tula->id])['resumen']['total'])->toBe(1);

    $this->actingAs($gerente)->post(route('reportes.rotacion.tablero.correcciones.causal'), ['fuente' => 'historico', 'id' => $ajena->id, 'causal_analitica_id' => rmCausal('salud')->id, 'comentario' => 'Intento ajeno'])->assertForbidden();

    $colaborador = clUsuario('colaborador');
    $this->actingAs($colaborador)->get(route('reportes.rotacion.tablero.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('reportes.rotacion.tablero.excel'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('reportes.rotacion.tablero.bitacora', ['fuente' => 'historico', 'id' => $ajena->id]))->assertForbidden();
});

test('la pantalla y las exportaciones dan las mismas cifras con los mismos filtros', function () {
    rmHistorica($this->cuernavaca, '2026-09-01');
    rmHistorica($this->tula, '2026-09-02', causal: null);
    rmExcel($this->cuernavaca, 2026, 2, 3, 1, 1);

    $consulta = ['periodo' => 'acumulado', 'anio' => 2026];

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.index', $consulta))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Reportes/Rotacion/Index')
            ->where('resumen.total', 5)
            ->where('resumen.identidad', true)
            ->where('puede.ver_personas', true)
            ->has('historico.data', 2));

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.excel', $consulta))->assertOk();
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.pdf', $consulta))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.pdf-detallado', $consulta))->assertOk();
});

test('el histórico pagina en el servidor, busca, filtra por desglose y solo ofrece acciones permitidas', function () {
    $baja = rmBaja($this->cuernavaca, 'RV-01', '2026-10-05');
    rmHistorica($this->tula, '2026-09-02', extra: ['nombre' => 'Zoila Histórica']);

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.index', ['q' => 'zoila']))
        ->assertInertia(fn (Assert $p) => $p->has('historico.data', 1)->where('historico.data.0.nombre', 'Zoila Histórica')->where('historico.data.0.acciones.ver_baja', null));

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.index', ['d_mes' => '2026-10']))
        ->assertInertia(fn (Assert $p) => $p->has('historico.data', 1)->where('historico.data.0.acciones.ver_baja', route('rh.bajas.show', $baja->id, false))->where('historico.data.0.acciones.corregir_causal', true));
});

test('importar el histórico individual: vista previa con hallazgos, liga duplicados del sistema y no crea nada inventado', function () {
    $baja = rmBaja($this->cuernavaca, 'RV-01', '2026-10-05');
    $admin = clUsuario('super_admin');
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->fromArray([
        ['Nombre completo', 'Número de empleado', 'Sucursal', 'Puesto', 'Área', 'Fecha de ingreso', 'Último día laborado', 'Motivo original', 'Causal homologada'],
        ['Pedro Histórico', null, 'Cuernavaca', null, null, '2026-01-10', '2026-03-15', 'Se fue a otra empresa', 'Mejor oferta laboral o salarial'],
        ['Pedro Histórico', null, 'Cuernavaca', null, null, '2026-05-01', '2026-08-20', 'Abandono', 'Abandono de empleo o faltas'],
        [$baja->nombre_colaborador, null, 'Cuernavaca', null, null, '2026-01-01', '2026-10-05', 'Renuncia', null],
        ['Sin Sucursal', null, 'Solariega', null, null, '2026-01-01', '2026-02-01', null, null],
    ]);
    $ruta = tempnam(sys_get_temp_dir(), 'bajas-hist-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    $this->actingAs($this->rh)->post(route('reportes.rotacion.tablero.historico.analizar'), ['archivo' => new UploadedFile($ruta, 'bajas.xlsx', null, null, true)])->assertForbidden();
    $this->actingAs($admin)->post(route('reportes.rotacion.tablero.historico.analizar'), ['archivo' => new UploadedFile($ruta, 'bajas.xlsx', null, null, true)])->assertRedirect();

    $importacion = ImportacionExcel::query()->where('tipo', BajasHistoricasImportService::TIPO)->sole();
    expect($importacion->filas_error)->toBe(1)
        ->and(collect($importacion->detalle)->pluck('estado')->all())->toBe(['nuevo', 'nuevo', 'duplicado_sistema', 'error'])
        ->and(Sucursal::query()->where('nombre', 'Solariega')->exists())->toBeFalse();

    // Con errores no se aplica nada.
    $this->actingAs($admin)->post(route('reportes.rotacion.tablero.historico.aplicar', $importacion), ['motivo' => 'Carga histórica 2026'])->assertSessionHasErrors('importacion');
    expect(BajaHistorica::query()->count())->toBe(0);
});

test('mapear una causal del catálogo reclasifica con bitácora y aviso de cuántas bajas cambian', function () {
    rmBaja($this->cuernavaca, 'RV-02', '2026-10-05');
    $causal = CausalRotacion::query()->where('clave', 'RV-02')->firstOrFail();

    expect($causal->causal_analitica_id)->toBe(rmCausal('no_le_gusto')->id);

    $this->actingAs($this->rh)->put(route('reportes.rotacion.tablero.causales.mapeo', $causal), ['causal_analitica_id' => rmCausal('personales_familiares')->id, 'comentario' => 'Criterio acordado con Dirección'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t): bool => str_contains($t['message'], '1 baja'));

    expect(RotacionCorreccion::query()->where('sujeto_tipo', 'causal_rotacion')->count())->toBe(1)
        ->and(collect(rmDatos($this->rh)['causales']['causales'])->pluck('clave')->all())->toBe(['personales_familiares']);
});

test('el diagnóstico es de solo lectura', function () {
    rmHistorica($this->cuernavaca, '2026-09-01');
    $antes = [BajaHistorica::query()->count(), Baja::query()->count(), RotacionCorreccion::query()->count()];

    $this->artisan('rotacion:diagnostico', ['--anio' => 2026, '--hasta' => '2026-10-23'])->assertSuccessful();

    expect([BajaHistorica::query()->count(), Baja::query()->count(), RotacionCorreccion::query()->count()])->toBe($antes)
        ->and(ClasificacionSalida::cases())->toHaveCount(3);
});
