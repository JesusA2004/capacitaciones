<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaHistorica;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\ImportacionExcel;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\ReporteGuardado;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Reportes\AltasNoiService;
use App\Services\Reportes\Propios\ReportePropioService;
use App\Services\Reportes\ReporteExcelService;
use App\Services\Reportes\ReportesRhService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

/*
| Sección 8 del encargo y escenarios 37-42: reportes nominales, NOI, aniversario
| IMSS, altas históricas y Mis reportes. Sin Chrome: el renderer se sustituye y
| captura el HTML.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->empresa = Empresa::factory()->create(['registro_patronal' => 'E123456789']);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Cuernavaca']);
    $this->otra = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Tula']);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor de Ruta']);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');

    $this->htmlPdf = new ArrayObject;
    $this->app->instance(BrowsershotRenderer::class, new class($this->htmlPdf) extends BrowsershotRenderer
    {
        /** @param ArrayObject<int, string> $html */
        public function __construct(private ArrayObject $html) {}

        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            $this->html->append($html);

            return '%PDF-1.4 prueba';
        }
    });
});

function colaboradorReporte(object $t, array $datos = []): Colaborador
{
    return Colaborador::factory()->create([
        'sucursal_principal_id' => $t->sucursal->id,
        'puesto_id' => $t->puesto->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2026-10-26',
        ...$datos,
    ]);
}

/**
 * Alcance global pero sin salarios.ver ni datos sensibles: lo que se prueba es
 * el permiso por campo, no el alcance.
 */
function usuarioGlobalSinSensibles(): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole('auditor');

    expect($usuario->can('salarios.ver'))->toBeFalse()
        ->and($usuario->can('reportes_rh.datos_sensibles'))->toBeFalse();

    return $usuario;
}

function definicionColaboradores(array $columnas, array $extra = []): array
{
    return ['fuente' => 'colaboradores', 'columnas' => $columnas, 'filtros' => [], 'orden' => [], 'diseno' => [], ...$extra];
}

test('Rotación salió del catálogo de reportes', function () {
    $claves = collect(app(ReportesRhService::class)->catalogo())->flatMap(fn ($g) => array_column($g['opciones'], 'value'));

    expect($claves)->not->toContain('rotacion')
        ->and($claves)->toContain('altas_mes', 'bajas_mes', 'altas_noi', 'incidencias_nomina', 'aniversario_imss', 'directorio_activo');
});

test('escenario 37: altas y bajas del mes son nominales y respetan filtros', function () {
    $persona = colaboradorReporte($this, ['name' => 'Mario', 'apellidos' => 'Aguilar']);
    colaboradorReporte($this, ['name' => 'Otra', 'apellidos' => 'Sucursal', 'sucursal_principal_id' => $this->otra->id]);

    Baja::query()->create([
        'folio' => uniqid('BJ-'),
        'creado_por' => $this->rh->id,
        'colaborador_id' => $persona->id,
        'nombre_colaborador' => 'Kevin Hernandez',
        'sucursal_id' => $this->sucursal->id,
        'puesto_id' => $this->puesto->id,
        'fecha_baja' => '2026-10-14',
        'forma_salida' => FormaSalida::RenunciaVoluntaria,
        'estatus' => EstatusBaja::EnRevisionRh,
    ]);
    Baja::query()->create([
        'folio' => uniqid('BJ-'),
        'creado_por' => $this->rh->id,
        'colaborador_id' => $persona->id,
        'nombre_colaborador' => 'Cancelada Fuera',
        'sucursal_id' => $this->sucursal->id,
        'puesto_id' => $this->puesto->id,
        'fecha_baja' => '2026-10-15',
        'estatus' => EstatusBaja::Cancelada,
    ]);

    $servicio = app(ReportesRhService::class);
    $filtros = ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31', 'sucursal_id' => $this->sucursal->id];
    $altas = $servicio->generar('altas_mes', $this->rh, $filtros);
    $bajas = $servicio->generar('bajas_mes', $this->rh, $filtros);

    expect($altas['columnas'])->toContain('Nombre', 'Fecha de ingreso', 'Sucursal', 'Puesto')
        ->and(array_column($altas['filas'], 0))->toContain('Mario Aguilar')->not->toContain('Otra Sucursal')
        ->and($altas['filas'][0][1])->toBe('26/10/2026')
        ->and($bajas['columnas'])->toContain('Fecha de último día', 'Forma de salida', 'Causal')
        ->and(array_column($bajas['filas'], 0))->toBe(['Kevin Hernandez'])
        ->and($bajas['filas'][0][1])->toBe('14/10/2026');
});

test('una baja en trámite cuenta en el reporte por su último día', function () {
    $persona = colaboradorReporte($this);
    Baja::query()->create(['folio' => uniqid('BJ-'), 'creado_por' => $this->rh->id, 'colaborador_id' => $persona->id, 'nombre_colaborador' => 'En Trámite', 'sucursal_id' => $this->sucursal->id, 'fecha_baja' => '2026-10-20', 'estatus' => EstatusBaja::EnRevisionRh]);

    $bajas = app(ReportesRhService::class)->generar('bajas_mes', $this->rh, ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31']);

    expect(array_column($bajas['filas'], 0))->toContain('En Trámite');
});

test('escenario 38: el reporte NOI detecta faltantes y no se presenta como validado', function () {
    colaboradorReporte($this, ['name' => 'Completo', 'apellidos' => 'Datos', 'rfc' => 'AUMA800101AB1', 'curp' => 'AUMA800101HMSGRR01', 'nss' => '12345678901', 'fecha_nacimiento' => '1980-01-01', 'genero' => 'masculino', 'domicilio' => 'Calle 1', 'domicilio_cp' => '62000', 'fecha_alta_imss' => '2026-10-26', 'salario_diario' => 466.67, 'salario_diario_integrado' => 490.5, 'tipo_contratacion' => 'periodo_prueba']);
    colaboradorReporte($this, ['name' => 'Falta', 'apellidos' => 'Rfc', 'rfc' => null, 'nss' => null]);

    $reporte = app(ReportesRhService::class)->generar('altas_noi', $this->rh, ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31']);
    $indice = array_search('Datos faltantes', $reporte['columnas'], true);
    $filaFalta = collect($reporte['filas'])->first(fn ($f) => $f[0] === 'Falta');

    expect($filaFalta[$indice])->toContain('RFC', 'NSS')
        ->and($reporte['faltantes'])->toBeGreaterThanOrEqual(1)
        ->and(implode(' ', $reporte['avisos']))->toContain('Pendiente de homologar')
        ->and(implode(' ', $reporte['avisos']))->toContain('Banco')
        ->and(json_encode($reporte))->not->toContain('validad');
});

test('NOI enmascara datos sensibles y salario sin permiso, y bloquea el layout', function () {
    colaboradorReporte($this, ['name' => 'Sensible', 'rfc' => 'AUMA800101AB1', 'nss' => '12345678901', 'salario_diario' => 466.67]);
    $auxiliar = usuarioGlobalSinSensibles();
    $auxiliar->givePermissionTo(['reportes_rh.ver', 'reportes_rh.exportar', 'reportes_rh.globales']);

    $reporte = app(ReportesRhService::class)->generar('altas_noi', $auxiliar, ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31']);
    $json = json_encode($reporte, JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('12345678901')->not->toContain('AUMA800101AB1')->not->toContain('466.67')
        ->toContain('•••• 8901')->toContain('Restringido');

    $this->actingAs($auxiliar)->get(route('rh.reportes.noi.exportar'))->assertForbidden();
    $this->actingAs($auxiliar)->put(route('rh.reportes.noi.layout'), ['formato' => 'csv', 'columnas' => [['campo' => 'rfc']]])->assertForbidden();
});

test('el layout de NOI se configura por pantalla y el archivo lo respeta', function () {
    colaboradorReporte($this, ['name' => 'Ana', 'apellidos' => 'Luna', 'rfc' => 'LUAA800101AB1', 'fecha_ingreso' => now('America/Mexico_City')->toDateString()]);

    $this->actingAs($this->rh)->put(route('rh.reportes.noi.layout'), [
        'formato' => 'xlsx',
        'columnas' => [['campo' => 'rfc', 'encabezado' => 'RFC_EMPLEADO'], ['campo' => 'nombre', 'encabezado' => 'NOMBRE']],
    ])->assertRedirect();

    $this->actingAs($this->rh)->put(route('rh.reportes.noi.layout'), ['formato' => 'xlsx', 'columnas' => [['campo' => 'contrasena']]])->assertSessionHasErrors('columnas');

    $libro = app(AltasNoiService::class)->exportar($this->rh, []);
    $hoja = $libro->getSheet(0);

    expect($hoja->getCell('A1')->getValue())->toBe('RFC_EMPLEADO')
        ->and($hoja->getCell('B1')->getValue())->toBe('NOMBRE')
        ->and($hoja->getCell('A2')->getValue())->toBe('LUAA800101AB1')
        ->and($libro->getSheetByName('Control')->getCell('A1')->getValue())->toContain('Pendiente de homologar');
});

test('escenario 39: incidencias usa el mismo servicio que nómina', function () {
    $reporte = app(ReportesRhService::class)->generar('incidencias_nomina', $this->rh, ['fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31']);

    expect($reporte['columnas'])->toContain('Tipo', 'Días en el periodo', 'Goce', 'Referencia')
        ->and($reporte['sin_grafica'])->toBeTrue();
});

test('escenario 40: el aniversario usa fecha de alta IMSS, no fecha de ingreso', function () {
    colaboradorReporte($this, ['name' => 'Imss', 'apellidos' => 'Noviembre', 'fecha_ingreso' => '2020-03-10', 'fecha_alta_imss' => '2019-11-04', 'nss' => '11111112210']);
    colaboradorReporte($this, ['name' => 'Ingreso', 'apellidos' => 'Noviembre', 'fecha_ingreso' => '2020-11-10', 'fecha_alta_imss' => '2020-03-10', 'nss' => '22222226631']);

    $reporte = app(ReportesRhService::class)->generar('aniversario_imss', $this->rh, ['mes' => 11]);

    expect(array_column($reporte['filas'], 0))->toBe(['Imss Noviembre'])
        ->and($reporte['filas'][0][4])->toBe('04/11/2019')
        ->and($reporte['filas'][0][1])->toBe('11111112210');

    $sinPermiso = usuarioGlobalSinSensibles();
    $sinPermiso->givePermissionTo(['reportes_rh.ver', 'reportes_rh.globales']);
    $enmascarado = app(ReportesRhService::class)->generar('aniversario_imss', $sinPermiso, ['mes' => 11]);

    expect($enmascarado['filas'][0][1])->toBe('•••• 2210');
});

test('el PDF del catálogo se imprime con Chrome y la identidad Mr. Lana', function () {
    colaboradorReporte($this);

    $respuesta = $this->actingAs($this->rh)->get(route('rh.reportes.pdf', ['reporte' => 'empleados_por_sucursal']));

    $respuesta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($this->htmlPdf[0])->toContain('Mr. Lana')->toContain('<svg');
});

test('sin Chrome el PDF avisa con un error claro y no cae a otro motor', function () {
    config(['pdf.fallback_dompdf' => true]);
    $this->app->instance(BrowsershotRenderer::class, new class extends BrowsershotRenderer
    {
        public function __construct() {}

        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            throw new PdfRendererException('El servicio de impresión (Chrome) no está disponible en este momento.');
        }
    });

    $this->actingAs($this->rh)->postJson(route('rh.reportes.mis-reportes.vista-previa-pdf'), ['definicion' => definicionColaboradores(['nombre_completo'])])
        ->assertStatus(503)
        ->assertJsonPath('message', 'No se pudo generar el PDF: El servicio de impresión (Chrome) no está disponible en este momento.');
});

test('escenario 41: un reporte guardado conserva columnas, orden de columnas, filtros y diseño', function () {
    $definicion = definicionColaboradores(['apellidos', 'nombre', 'sucursal'], [
        'filtros' => [['campo' => 'sucursal', 'operador' => 'es', 'valor' => 'Cuernavaca']],
        'orden' => [['campo' => 'apellidos', 'direccion' => 'desc']],
        'diseno' => ['titulo' => 'Plantilla Cuernavaca', 'color' => 'bronce', 'orientacion' => 'portrait', 'totales' => true, 'grafica' => ['activa' => true, 'tipo' => 'pastel', 'campo' => 'sucursal', 'valor' => 'conteo']],
    ]);

    $this->actingAs($this->rh)->post(route('rh.reportes.mis-reportes.store'), ['nombre' => 'Cuernavaca', 'definicion' => $definicion])->assertRedirect();

    $reporte = ReporteGuardado::query()->sole();

    expect($reporte->user_id)->toBe($this->rh->id)
        ->and($reporte->definicion['columnas'])->toBe(['apellidos', 'nombre', 'sucursal'])
        ->and($reporte->definicion['filtros'][0]['valor'])->toBe('Cuernavaca')
        ->and($reporte->definicion['orden'][0]['direccion'])->toBe('desc')
        ->and($reporte->definicion['diseno']['color'])->toBe('bronce')
        ->and($reporte->definicion['diseno']['grafica']['tipo'])->toBe('pastel');
});

test('escenario 42: un reporte guardado se regenera con datos actuales y no guarda datos', function () {
    colaboradorReporte($this, ['name' => 'Primera']);
    $this->actingAs($this->rh)->post(route('rh.reportes.mis-reportes.store'), ['nombre' => 'Vivo', 'definicion' => definicionColaboradores(['nombre'])]);
    $reporte = ReporteGuardado::query()->sole();

    expect(json_encode($reporte->definicion))->not->toContain('Primera');

    colaboradorReporte($this, ['name' => 'Nueva']);
    $resultado = app(ReportePropioService::class)->ejecutar($reporte->definicion, $this->rh);

    expect(array_merge(...$resultado['filas']))->toContain('Primera', 'Nueva');
});

test('editar, duplicar, ejecutar y eliminar; solo su dueño', function () {
    colaboradorReporte($this);
    $this->actingAs($this->rh)->post(route('rh.reportes.mis-reportes.store'), ['nombre' => 'Base', 'definicion' => definicionColaboradores(['nombre'])]);
    $reporte = ReporteGuardado::query()->sole();

    $this->actingAs($this->rh)->put(route('rh.reportes.mis-reportes.update', $reporte), ['nombre' => 'Base 2', 'definicion' => definicionColaboradores(['apellidos'])])->assertRedirect();
    expect($reporte->fresh()->definicion['columnas'])->toBe(['apellidos']);

    $this->actingAs($this->rh)->post(route('rh.reportes.mis-reportes.duplicar', $reporte))->assertRedirect();
    expect(ReporteGuardado::query()->where('nombre', 'Base 2 (copia)')->exists())->toBeTrue();

    $this->actingAs($this->rh)->get(route('rh.reportes.mis-reportes.ejecutar', [$reporte, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->rh)->get(route('rh.reportes.mis-reportes.ejecutar', [$reporte, 'excel']))->assertOk();

    $ajeno = User::factory()->create();
    $ajeno->givePermissionTo(['reportes_rh.ver', 'reportes_rh.exportar', 'reportes_rh.mis_reportes']);
    $this->actingAs($ajeno)->get(route('rh.reportes.mis-reportes.ejecutar', [$reporte, 'pdf']))->assertForbidden();
    $this->actingAs($ajeno)->put(route('rh.reportes.mis-reportes.update', $reporte), ['nombre' => 'x', 'definicion' => definicionColaboradores(['nombre'])])->assertForbidden();
    $this->actingAs($ajeno)->delete(route('rh.reportes.mis-reportes.destroy', $reporte))->assertForbidden();

    $this->actingAs($this->rh)->delete(route('rh.reportes.mis-reportes.destroy', $reporte))->assertRedirect();
    expect(ReporteGuardado::query()->whereKey($reporte->id)->exists())->toBeFalse();
});

test('un guardado con sueldo no lo expone a quien no tiene salarios.ver, tampoco por filtro, orden ni gráfica', function () {
    colaboradorReporte($this, ['name' => 'Alto', 'sueldo_mensual' => 90000]);
    colaboradorReporte($this, ['name' => 'Bajo', 'sueldo_mensual' => 9000]);

    $definicion = definicionColaboradores(['nombre', 'sueldo_mensual'], [
        'filtros' => [['campo' => 'sueldo_mensual', 'operador' => 'mayor', 'valor' => 50000]],
        'orden' => [['campo' => 'sueldo_mensual', 'direccion' => 'desc']],
        'diseno' => ['grafica' => ['activa' => true, 'tipo' => 'barras', 'campo' => 'nombre', 'valor' => 'sueldo_mensual']],
    ]);

    // Guardado cuando sí tenía permiso (rh_admin también tiene salarios.ver).
    $conPermiso = app(ReportePropioService::class)->normalizar($definicion, $this->rh);
    $sinPermiso = usuarioGlobalSinSensibles();
    $sinPermiso->givePermissionTo(['reportes_rh.ver', 'reportes_rh.globales', 'reportes_rh.mis_reportes', 'reportes_rh.exportar']);

    $resultado = app(ReportePropioService::class)->ejecutar($conPermiso, $sinPermiso);
    $json = json_encode($resultado, JSON_UNESCAPED_UNICODE);

    expect(array_column($resultado['columnas'], 'clave'))->toBe(['nombre'])
        ->and($resultado['omitidas'])->toBe(['Sueldo mensual'])
        ->and($resultado['total'])->toBe(Colaborador::query()->count())
        ->and($resultado['grafica'])->toBeNull()
        ->and($json)->not->toContain('90000')->not->toContain('50000');

    $this->actingAs($sinPermiso)->postJson(route('rh.reportes.mis-reportes.vista-previa'), ['definicion' => $definicion])
        ->assertUnprocessable();
});

test('la definición rechaza fuentes, campos y operadores fuera del catálogo', function (array $cambio, string $error) {
    $base = definicionColaboradores(['nombre']);

    $this->actingAs($this->rh)->postJson(route('rh.reportes.mis-reportes.vista-previa'), ['definicion' => [...$base, ...$cambio]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'fuente desconocida' => [['fuente' => 'users'], 'fuente'],
    'columna inexistente' => [['columnas' => ['password']], 'columnas'],
    'sql como columna' => [['columnas' => ['name; drop table users']], 'columnas'],
    'filtro con operador libre' => [['filtros' => [['campo' => 'nombre', 'operador' => '; delete from users', 'valor' => 'x']]], 'filtros'],
    'operador de otro tipo' => [['filtros' => [['campo' => 'nombre', 'operador' => 'mayor', 'valor' => '1']]], 'filtros'],
    'fecha inválida' => [['filtros' => [['campo' => 'fecha_ingreso', 'operador' => 'desde', 'valor' => 'no-es-fecha']]], 'filtros'],
    'filtro sin valor' => [['filtros' => [['campo' => 'nombre', 'operador' => 'es', 'valor' => '']]], 'filtros'],
    'orden inexistente' => [['orden' => [['campo' => 'password', 'direccion' => 'asc']]], 'orden'],
]);

test('los operadores filtran y el alcance por sucursal se aplica al ejecutar', function () {
    colaboradorReporte($this, ['name' => 'Dentro', 'fecha_ingreso' => '2026-05-10']);
    colaboradorReporte($this, ['name' => 'Fuera', 'fecha_ingreso' => '2025-01-01', 'sucursal_principal_id' => $this->otra->id]);

    $servicio = app(ReportePropioService::class);
    $definicion = definicionColaboradores(['nombre'], ['filtros' => [['campo' => 'fecha_ingreso', 'operador' => 'entre', 'valor' => ['2026-01-01', '2026-12-31']]]]);
    $resultado = $servicio->ejecutar($servicio->normalizar($definicion, $this->rh), $this->rh);

    expect(array_merge(...$resultado['filas']))->toContain('Dentro')->not->toContain('Fuera')
        ->and($resultado['filtros_texto'][0])->toContain('01/01/2026');

    $gerente = User::factory()->create();
    $gerente->assignRole('gerente_sucursal');
    $gerente->givePermissionTo('reportes_rh.mis_reportes');
    $colaboradorGerente = colaboradorReporte($this, ['name' => 'Gerente']);
    $gerente->update(['colaborador_id' => $colaboradorGerente->id]);
    $delGerente = $servicio->ejecutar(definicionColaboradores(['nombre']), $gerente->fresh());

    expect(array_merge(...$delGerente['filas']))->not->toContain('Fuera');
});

test('una fuente sin permiso se rechaza al ejecutar, aunque ya esté guardada', function () {
    $sinFuente = User::factory()->create();
    $sinFuente->givePermissionTo(['reportes_rh.ver', 'reportes_rh.mis_reportes']);

    $this->actingAs($sinFuente)->postJson(route('rh.reportes.mis-reportes.vista-previa'), ['definicion' => ['fuente' => 'incapacidades', 'columnas' => ['tipo']]])
        ->assertUnprocessable();

    expect(fn () => app(ReportePropioService::class)->ejecutar(['fuente' => 'incapacidades', 'columnas' => ['tipo']], $sinFuente))
        ->toThrow(AuthorizationException::class);
});

test('sin el permiso de Mis reportes no se entra a ninguna acción', function () {
    $usuario = User::factory()->create();
    $usuario->givePermissionTo('reportes_rh.ver');

    $this->actingAs($usuario)->get(route('rh.reportes.mis-reportes.index'))->assertForbidden();
    $this->actingAs($usuario)->postJson(route('rh.reportes.mis-reportes.vista-previa'), ['definicion' => definicionColaboradores(['nombre'])])->assertForbidden();
    $this->actingAs($usuario)->postJson(route('rh.reportes.mis-reportes.exportar'), ['definicion' => definicionColaboradores(['nombre'])])->assertForbidden();
});

test('el Excel lleva diseño, totales, gráfica nativa y texto seguro contra fórmulas, y se vuelve a abrir', function () {
    colaboradorReporte($this, ['name' => '=CMD()', 'sueldo_mensual' => 10000]);
    colaboradorReporte($this, ['name' => 'Ana', 'sueldo_mensual' => 20000]);

    $servicio = app(ReportePropioService::class);
    $resultado = $servicio->ejecutar($servicio->normalizar(definicionColaboradores(['nombre', 'fecha_ingreso', 'sueldo_mensual'], [
        'filtros' => [['campo' => 'sucursal', 'operador' => 'es', 'valor' => 'Cuernavaca']],
        'diseno' => ['titulo' => 'Sueldos', 'color' => 'bosque', 'totales' => true, 'grafica' => ['activa' => true, 'tipo' => 'barras', 'campo' => 'sucursal', 'valor' => 'sueldo_mensual']],
    ]), $this->rh), $this->rh);

    $excel = app(ReporteExcelService::class);
    $libro = $excel->generar($resultado);
    $ruta = tempnam(sys_get_temp_dir(), 'reporte').'.xlsx';
    $excel->escritor($libro)->save($ruta);

    $reader = IOFactory::createReader('Xlsx');
    $reader->setIncludeCharts(true);
    $abierto = $reader->load($ruta);
    $hoja = $abierto->getSheetByName('Reporte');
    $celdas = collect($hoja->toArray(null, false, false, false))->flatten()->filter()->values()->all();

    expect($hoja->getCell('A1')->getValue())->toBe('Sueldos')
        ->and($celdas)->toContain('=CMD()')
        ->and($hoja->getStyle('A1')->getFill()->getStartColor()->getRGB())->toBe('2F5937')
        ->and($celdas)->toContain(30000.0)
        ->and($abierto->getSheetByName('Gráfica')->getChartCollection())->toHaveCount(1);

    unlink($ruta);
});

test('la exportación deja bitácora sin valores', function () {
    colaboradorReporte($this, ['name' => 'Secreto']);

    $this->actingAs($this->rh)->post(route('rh.reportes.mis-reportes.exportar'), ['formato' => 'excel', 'definicion' => definicionColaboradores(['nombre'])])->assertOk();

    $registro = Activity::query()->where('log_name', 'reportes')->latest('id')->first();

    expect($registro)->not->toBeNull()
        ->and($registro->properties['columnas'])->toBe(['nombre'])
        ->and(json_encode($registro->properties))->not->toContain('Secreto');
});

/**
 * @param  array<int, array<int, string|null>>  $renglones
 */
function archivoAltas(array $renglones): UploadedFile
{
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->fromArray([['Nombre', 'Fecha de alta', 'Sucursal', 'Puesto', 'Número de empleado'], ...$renglones]);
    $ruta = tempnam(sys_get_temp_dir(), 'altas').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, 'altas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('altas históricas: solo super_admin, con vista previa, sin tocar fechas de ingreso', function () {
    $this->actingAs($this->rh)->post(route('administracion.importar-datos.altas-historicas.analizar'), ['archivo' => archivoAltas([])])->assertForbidden();

    $persona = colaboradorReporte($this, ['name' => 'Rosa', 'apellidos' => 'Luna', 'fecha_ingreso' => '2026-10-26']);
    $super = User::factory()->create();
    $super->assignRole('super_admin');

    $this->actingAs($super)->post(route('administracion.importar-datos.altas-historicas.analizar'), [
        'archivo' => archivoAltas([['Rosa Luna', '15/01/2026', 'Cuernavaca', 'Gestor de Ruta', null], ['Pedro Paz', '20/02/2026', 'Tula', 'Gestor de Ruta', null]]),
    ])->assertRedirect();

    $importacion = ImportacionExcel::query()->where('tipo', 'altas_historicas')->sole();

    expect($importacion->estado)->toBe('previsualizado')
        ->and($importacion->filas_revision)->toBe(2)
        ->and(AltaHistorica::query()->count())->toBe(0);

    $this->actingAs($super)->post(route('administracion.importar-datos.altas-historicas.aplicar', $importacion), ['motivo' => 'Plantilla entregada por RH'])->assertRedirect();

    expect(AltaHistorica::query()->count())->toBe(2)
        ->and($persona->fresh()->fecha_ingreso->toDateString())->toBe('2026-10-26')
        ->and(AltaHistorica::query()->where('nombre', 'Rosa Luna')->value('colaborador_id'))->toBe($persona->id);

    $this->actingAs($super)->post(route('administracion.importar-datos.altas-historicas.aplicar', $importacion), ['motivo' => 'Otra vez intento'])->assertSessionHasErrors('importacion');
    expect(AltaHistorica::query()->count())->toBe(2);

    // Reporte: la histórica aparece y se distingue, sin duplicarse.
    $altas = app(ReportesRhService::class)->generar('altas_mes', $this->rh, ['fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-02-28']);
    expect(array_column($altas['filas'], 0))->toBe(['Rosa Luna', 'Pedro Paz'])
        ->and($altas['filas'][0][4])->toBe('Histórico');
});

test('altas históricas: filas que no cuadran se reportan y bloquean', function () {
    $super = User::factory()->create();
    $super->assignRole('super_admin');

    $this->actingAs($super)->post(route('administracion.importar-datos.altas-historicas.analizar'), [
        'archivo' => archivoAltas([
            ['', '15/01/2026', 'Cuernavaca', 'Gestor de Ruta', null],
            ['Sin Fecha', 'ayer', 'Cuernavaca', 'Gestor de Ruta', null],
            ['Otra Sucursal', '15/01/2026', 'Marte', 'Gestor de Ruta', null],
            ['Otro Puesto', '15/01/2026', 'Cuernavaca', 'Astronauta', null],
            ['Futura', '15/01/2099', 'Cuernavaca', 'Gestor de Ruta', null],
            ['Doble', '15/01/2026', 'Cuernavaca', 'Gestor de Ruta', null],
            ['Doble', '15/01/2026', 'Cuernavaca', 'Gestor de Ruta', null],
        ]),
    ]);

    $importacion = ImportacionExcel::query()->where('tipo', 'altas_historicas')->sole();

    expect($importacion->filas_error)->toBe(6);

    $this->actingAs($super)->post(route('administracion.importar-datos.altas-historicas.aplicar', $importacion), ['motivo' => 'Con errores'])->assertSessionHasErrors('importacion');
    expect(AltaHistorica::query()->count())->toBe(0);
});

test('altas por mes distingue periodos sin información de meses en cero', function () {
    $hoy = now('America/Mexico_City');
    Colaborador::query()->update(['fecha_ingreso' => $hoy->copy()->subMonths(2)->toDateString()]);
    colaboradorReporte($this, ['fecha_ingreso' => $hoy->copy()->subMonths(2)->toDateString()]);

    $reporte = app(ReportesRhService::class)->generar('altas_por_mes', $this->rh, []);

    expect($reporte['columnas'])->toBe(['Mes', 'Total', 'Información'])
        ->and($reporte['filas'][0][1])->toBeNull()
        ->and($reporte['filas'][0][2])->toBe('Sin información')
        ->and($reporte['filas'][11][1])->toBe(0)
        ->and($reporte['filas'][11][2])->toBe('Con información')
        ->and($reporte['filas'][9][1])->toBe(Colaborador::query()->count());
});

test('el movimiento de alta no se duplica con la fecha de ingreso', function () {
    $persona = colaboradorReporte($this, ['name' => 'Unica', 'fecha_ingreso' => '2026-10-10']);
    MovimientoLaboral::query()->create(['colaborador_id' => $persona->id, 'tipo_movimiento' => TipoMovimientoLaboral::Alta->value, 'fecha_movimiento' => '2026-10-10', 'sucursal_nueva_id' => $this->sucursal->id, 'puesto_nuevo_id' => $this->puesto->id]);

    $altas = app(ReportesRhService::class)->generar('altas_mes', $this->rh, ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31']);

    expect(collect($altas['filas'])->filter(fn ($f) => str_starts_with((string) $f[0], 'Unica'))->count())->toBe(1);
});
