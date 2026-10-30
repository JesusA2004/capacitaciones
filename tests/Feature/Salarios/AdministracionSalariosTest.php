<?php

use App\Enums\BandaSalarial;
use App\Enums\NivelDesempeno;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\ImportacionExcel;
use App\Models\MatrizMerito;
use App\Models\Puesto;
use App\Models\TabuladorSalarial;
use App\Models\User;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Salarios\PosicionSalarialService;
use App\Services\Salarios\TabuladorSalarialService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;

/*
| Administración de salarios (PDF págs. 18-19): el tabulador nunca cambia sueldos,
| la matriz solo sugiere, y sin salarios.* no hay forma de leer un importe.
*/

function salariosUsuario(string $rol): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    return $usuario;
}

/** @param  list<list<mixed>>  $renglones */
function tabuladorArchivo(array $renglones): UploadedFile
{
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->fromArray([['Puesto', 'Mínimo', 'Medio', 'Máximo', 'Fuente', 'Fecha de actualización'], ...$renglones]);
    $ruta = tempnam(sys_get_temp_dir(), 'tabulador-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, 'tabulador.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

beforeEach(function () {
    // El PDF sale con Browsershot: en pruebas no hace falta Chrome.
    $this->app->instance(BrowsershotRenderer::class, new class extends BrowsershotRenderer
    {
        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            return '%PDF-1.4 prueba';
        }
    });
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $this->rh = salariosUsuario('rh_admin');
    $this->rango = ['minimo' => 15000, 'medio' => 18000, 'maximo' => 21000, 'fuente' => 'Estudio de mercado', 'fecha_actualizacion' => '2026-07-01'];
});

test('escenario 34: $17,000 contra medio $18,000 da compa-ratio 94.44 y queda en mínimo a medio', function () {
    Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'estatus' => 'activo', 'sueldo_mensual' => 17000]);
    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango)->assertRedirect();

    $this->actingAs($this->rh)->get(route('salarios.index', ['puesto' => $this->puesto->id]))
        ->assertInertia(fn (Assert $p) => $p->component('Administracion/Salarios/Index')
            ->where('colaboradores.0.compa_ratio', 94.44)
            ->where('colaboradores.0.banda', 'minimo_a_medio')
            ->where('resumen.puestos_con_tabulador', 1));

    expect(round(PosicionSalarialService::compaRatio(17000, 18000) ?? 0))->toBe(94.0);
});

test('las cuatro posiciones de la banda, con sus límites', function (float $sueldo, BandaSalarial $banda) {
    expect(PosicionSalarialService::banda($sueldo, 15000, 18000, 21000))->toBe($banda);
})->with([
    [14999.99, BandaSalarial::BajoMinimo],
    [15000, BandaSalarial::MinimoAMedio],
    [18000, BandaSalarial::MedioAMaximo],
    [21000, BandaSalarial::MedioAMaximo],
    [21000.01, BandaSalarial::SobreMaximo],
]);

test('escenario 33: guardar e importar tabulador no modifica sueldos ni historial salarial', function () {
    $colaborador = Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'estatus' => 'activo', 'sueldo_mensual' => 17000]);
    $historial = HistorialSalario::query()->count();

    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango)->assertRedirect();

    $admin = salariosUsuario('super_admin');
    $this->actingAs($admin)->post(route('salarios.importar.analizar'), ['archivo' => tabuladorArchivo([['Gerente de Sucursal', 16000, 19000, 22000, 'Estudio 2027', '2026-08-01']])])->assertRedirect();
    $importacion = ImportacionExcel::query()->where('tipo', 'tabulador_salarial')->latest('id')->firstOrFail();
    $this->actingAs($admin)->post(route('salarios.importar.aplicar', $importacion), ['motivo' => 'Estudio de mercado 2027'])->assertRedirect();

    expect((float) $colaborador->fresh()->sueldo_mensual)->toBe(17000.0)
        ->and(HistorialSalario::query()->count())->toBe($historial)
        ->and(TabuladorSalarial::query()->where('puesto_id', $this->puesto->id)->count())->toBe(2);
});

test('cada cambio crea versión, cierra la anterior y repetir lo mismo no crea otra', function () {
    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango);
    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango);
    expect(TabuladorSalarial::query()->count())->toBe(1);

    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), [...$this->rango, 'medio' => 18500]);

    $versiones = TabuladorSalarial::query()->orderBy('version')->get();
    expect($versiones)->toHaveCount(2)
        ->and($versiones[0]->vigente_hasta)->not->toBeNull()
        ->and($versiones[1]->vigente_hasta)->toBeNull()
        ->and($versiones[1]->version)->toBe(2);
});

test('rechaza un rango que no cumple mínimo ≤ medio ≤ máximo', function (array $cambio) {
    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), [...$this->rango, ...$cambio])->assertSessionHasErrors();
    expect(TabuladorSalarial::query()->count())->toBe(0);
})->with([
    [['medio' => 14000]],
    [['maximo' => 17000]],
    [['minimo' => 0]],
]);

test('el servicio también rechaza el rango inválido, sin pasar por la pantalla', function () {
    app(TabuladorSalarialService::class)->guardar($this->puesto, [...$this->rango, 'medio' => 30000], $this->rh);
})->throws(ValidationException::class);

test('sin evaluación vigente no inventa tope ni margen', function () {
    Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'estatus' => 'activo', 'sueldo_mensual' => 17000]);
    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango);

    $this->actingAs($this->rh)->get(route('salarios.index', ['puesto' => $this->puesto->id]))
        ->assertInertia(fn (Assert $p) => $p
            ->where('colaboradores.0.desempeno_etiqueta', 'Sin evaluación de desempeño vigente')
            ->where('colaboradores.0.tope', null)
            ->where('colaboradores.0.margen', null)
            ->where('colaboradores.0.sugerido', null)
            ->where('resumen.margen', null));
});

test('topes y sugerencia según desempeño, sin aplicar aumentos (escenario 36)', function (NivelDesempeno $nivel, ?float $tope, float $margen, float|string|null $sugerido) {
    $colaborador = Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'estatus' => 'activo', 'sueldo_mensual' => 17000]);
    $tabulador = app(TabuladorSalarialService::class)->guardar($this->puesto, $this->rango, $this->rh);
    $historial = HistorialSalario::query()->count();

    $fila = app(PosicionSalarialService::class)->fila($colaborador, $tabulador, $nivel, MatrizMerito::query()->firstOrFail()->celdas);

    expect($fila['tope'])->toBe($tope)
        ->and($fila['margen'])->toBe($margen)
        ->and($fila['sugerido'])->toBe($sugerido)
        ->and((float) $colaborador->fresh()->sueldo_mensual)->toBe(17000.0)
        ->and(HistorialSalario::query()->count())->toBe($historial);
})->with([
    'sobresaliente hasta el máximo' => [NivelDesempeno::Sobresaliente, 21000.0, 4000.0, 8.0],
    'satisfactorio hasta el medio' => [NivelDesempeno::Satisfactorio, 18000.0, 1000.0, 5.0],
    'bajo sin incremento' => [NivelDesempeno::Bajo, null, 0.0, 0.0],
]);

test('sobre el máximo y sobresaliente sugiere bono y no da margen negativo', function () {
    $colaborador = Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'estatus' => 'activo', 'sueldo_mensual' => 25000]);
    $tabulador = app(TabuladorSalarialService::class)->guardar($this->puesto, $this->rango, $this->rh);

    $fila = app(PosicionSalarialService::class)->fila($colaborador, $tabulador, NivelDesempeno::Sobresaliente, MatrizMerito::query()->firstOrFail()->celdas);

    expect($fila['sugerido'])->toBe('bono')->and($fila['margen'])->toBe(0.0);
});

test('la matriz guarda versión con historial y valida los porcentajes', function () {
    $celdas = MatrizMerito::query()->firstOrFail()->celdas;
    $celdas['satisfactorio']['bajo_minimo'] = 6;

    $this->actingAs($this->rh)->put(route('salarios.matriz.guardar'), ['celdas' => $celdas, 'motivo' => 'Ajuste anual'])->assertRedirect();

    expect(MatrizMerito::query()->count())->toBe(2)
        ->and(MatrizMerito::query()->whereNull('vigente_hasta')->count())->toBe(1)
        ->and(MatrizMerito::query()->whereNull('vigente_hasta')->firstOrFail()->celdas['satisfactorio']['bajo_minimo'])->toEqual(6);

    $celdas['bajo']['bajo_minimo'] = 150;
    $this->actingAs($this->rh)->put(route('salarios.matriz.guardar'), ['celdas' => $celdas])->assertSessionHasErrors('celdas');
    expect(MatrizMerito::query()->count())->toBe(2);
});

test('escenario 35: sin permiso salarios.* no se llega por URL, parámetros ni exportación', function (string $rol) {
    Colaborador::factory()->create(['puesto_id' => $this->puesto->id, 'estatus' => 'activo', 'sueldo_mensual' => 17000]);
    $usuario = salariosUsuario($rol);

    expect($usuario->can('salarios.ver'))->toBeFalse();

    $this->actingAs($usuario)->get(route('salarios.index', ['puesto' => $this->puesto->id]))->assertForbidden();
    $this->actingAs($usuario)->get(route('salarios.exportar.excel', ['vista' => 'posiciones']))->assertForbidden();
    $this->actingAs($usuario)->get(route('salarios.exportar.pdf', ['vista' => 'posiciones']))->assertForbidden();
    $this->actingAs($usuario)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango)->assertForbidden();
    $this->actingAs($usuario)->put(route('salarios.matriz.guardar'), ['celdas' => []])->assertForbidden();
    $this->actingAs($usuario)->get(route('salarios.importar.formato'))->assertForbidden();

    expect(TabuladorSalarial::query()->count())->toBe(0);
})->with(['gerente', 'gerente_sucursal', 'gerente_regional', 'rh_auxiliar', 'colaborador']);

test('ninguna ruta de la API expone el módulo de salarios', function () {
    $rutas = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());

    expect($rutas->filter(fn (string $uri): bool => str_starts_with($uri, 'api/') && str_contains($uri, 'salarios') && ! str_contains($uri, 'importar'))->values()->all())->toBe([]);
});

test('Dirección consulta y exporta pero no captura; RH no importa', function () {
    $direccion = salariosUsuario('direccion');

    $this->actingAs($direccion)->get(route('salarios.index'))->assertOk();
    $this->actingAs($direccion)->get(route('salarios.exportar.excel'))->assertOk();
    $this->actingAs($direccion)->get(route('salarios.exportar.pdf', ['vista' => 'posiciones']))->assertOk();
    $this->actingAs($direccion)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango)->assertForbidden();

    $this->actingAs($this->rh)->post(route('salarios.importar.analizar'), ['archivo' => tabuladorArchivo([['Gerente de Sucursal', 1, 2, 3, 'x', '2026-01-01']])])->assertForbidden();
});

test('consultas, exportaciones y cambios quedan en bitácora sin importes', function () {
    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), $this->rango);
    $this->actingAs($this->rh)->get(route('salarios.index'));
    $this->actingAs($this->rh)->get(route('salarios.exportar.excel', ['vista' => 'posiciones']));

    $actividades = Activity::query()->whereIn('event', ['salarios.tabulador_actualizado', 'salarios.consultado', 'salarios.exportado'])->get();

    expect($actividades->pluck('event')->unique()->values()->all())->toEqualCanonicalizing(['salarios.tabulador_actualizado', 'salarios.consultado', 'salarios.exportado']);

    $texto = (string) $actividades->map(fn (Activity $a) => $a->properties->toJson())->implode(' ');
    expect($texto)->not->toContain('15000')->not->toContain('18000')->not->toContain('21000');
});

test('la importación reporta puesto inexistente, duplicado y rango inválido, y no aplica', function () {
    $admin = salariosUsuario('super_admin');

    $this->actingAs($admin)->post(route('salarios.importar.analizar'), ['archivo' => tabuladorArchivo([
        ['Gerente de Sucursal', 15000, 18000, 21000, 'Estudio', '2026-07-01'],
        ['gerente de sucursal', 15000, 18000, 21000, 'Estudio', '2026-07-01'],
        ['Puesto Fantasma', 15000, 18000, 21000, 'Estudio', '2026-07-01'],
    ])])->assertRedirect();

    $importacion = ImportacionExcel::query()->where('tipo', 'tabulador_salarial')->latest('id')->firstOrFail();
    expect($importacion->filas_error)->toBe(2)
        ->and(TabuladorSalarial::query()->count())->toBe(0);

    $this->actingAs($admin)->post(route('salarios.importar.aplicar', $importacion), ['motivo' => 'Carga de prueba'])->assertSessionHasErrors('importacion');
    expect(TabuladorSalarial::query()->count())->toBe(0);
});

test('un puesto del formato sin importes se omite y faltar columnas se rechaza', function () {
    $admin = salariosUsuario('super_admin');

    $this->actingAs($admin)->post(route('salarios.importar.analizar'), ['archivo' => tabuladorArchivo([
        ['Gerente de Sucursal', null, null, null, null, null],
        ['Gerente de Sucursal', 15000, 18000, 21000, 'Estudio', '2026-07-01'],
    ])])->assertRedirect();
    expect(ImportacionExcel::query()->where('tipo', 'tabulador_salarial')->latest('id')->firstOrFail()->filas_error)->toBe(0);

    $libro = new Spreadsheet;
    $libro->getActiveSheet()->fromArray([['Puesto', 'Mínimo']]);
    $ruta = tempnam(sys_get_temp_dir(), 'tabulador-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    $this->actingAs($admin)->post(route('salarios.importar.analizar'), ['archivo' => new UploadedFile($ruta, 'x.xlsx', null, null, true)])->assertSessionHasErrors('archivo');
});

test('aplicar dos veces o con la versión cambiada se rechaza', function () {
    $admin = salariosUsuario('super_admin');

    $this->actingAs($admin)->post(route('salarios.importar.analizar'), ['archivo' => tabuladorArchivo([['Gerente de Sucursal', 15000, 18000, 21000, 'Estudio', '2026-07-01']])]);
    $importacion = ImportacionExcel::query()->where('tipo', 'tabulador_salarial')->latest('id')->firstOrFail();

    $this->actingAs($this->rh)->put(route('salarios.tabulador.guardar', $this->puesto), [...$this->rango, 'medio' => 17000]);
    $this->actingAs($admin)->post(route('salarios.importar.aplicar', $importacion), ['motivo' => 'Carga de prueba'])->assertSessionHasErrors('importacion');
    expect(TabuladorSalarial::query()->count())->toBe(1);
});

test('sin datos: cero colaboradores no divide entre cero y puesto sin tabulador queda en null', function () {
    $this->actingAs($this->rh)->get(route('salarios.index'))
        ->assertInertia(fn (Assert $p) => $p
            ->where('resumen.compa_ratio_promedio', null)
            ->where('resumen.margen', null)
            ->where('tabulador.0.version', null));
});
