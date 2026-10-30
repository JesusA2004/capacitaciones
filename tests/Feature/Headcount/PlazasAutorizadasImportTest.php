<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\ImportacionExcel;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| Importación auditable de plazas autorizadas: Corporativo como ubicación más,
| nada se escribe sin confirmar y lo que no cuadra se reporta, nunca se adivina.
*/

/**
 * @param  list<list<mixed>>  $renglones
 */
function plazasArchivo(array $renglones, array $encabezado = ['Sucursal', 'Puesto', 'Plazas autorizadas']): UploadedFile
{
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->fromArray([$encabezado, ...$renglones]);
    $ruta = tempnam(sys_get_temp_dir(), 'plazas-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, 'plazas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true]);
    $this->analista = Puesto::factory()->create(['nombre' => 'Analista de Mesa de Control', 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'activo' => true]);
    Puesto::factory()->create(['nombre' => 'Gestor Volante', 'activo' => true]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->tula->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 4]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

function plazasAnalizar($test, UploadedFile $archivo): ImportacionExcel
{
    $test->actingAs($test->admin)
        ->post(route('administracion.importar-datos.plazas-autorizadas.analizar'), ['archivo' => $archivo])
        ->assertRedirect();

    return ImportacionExcel::query()->where('tipo', 'plazas_autorizadas')->latest('id')->firstOrFail();
}

test('la vista previa no escribe plazas y muestra la conciliación antes y después', function () {
    Colaborador::factory()->create(['sucursal_principal_id' => $this->corporativo->id, 'puesto_id' => $this->analista->id, 'estatus' => 'activo']);

    $importacion = plazasAnalizar($this, plazasArchivo([['Corporativo', 'Analista de Mesa de Control', 6], ['TULA', 'gestor', 5]]));

    expect(HeadcountTarget::query()->where('sucursal_id', $this->corporativo->id)->exists())->toBeFalse()
        ->and($importacion->estado)->toBe('previsualizado')
        ->and($importacion->filas_revision)->toBe(2)
        ->and($importacion->metadatos['conciliacion']['antes']['total']['autorizadas'])->toBe(4)
        ->and($importacion->metadatos['conciliacion']['despues']['total']['autorizadas'])->toBe(11)
        ->and($importacion->metadatos['conciliacion']['despues']['corporativo'])->toMatchArray(['autorizadas' => 6, 'ocupadas' => 1, 'vacantes' => 5]);
});

test('aplicar carga Corporativo con bitácora, motivo y actividad', function () {
    $importacion = plazasAnalizar($this, plazasArchivo([['Corporativo', 'Analista de Mesa de Control', 6], ['Tula', 'Gestor', 5]]));

    $this->actingAs($this->admin)
        ->post(route('administracion.importar-datos.plazas-autorizadas.aplicar', $importacion), ['motivo' => 'Autorizado por Dirección, oficio 12'])
        ->assertRedirect();

    $target = HeadcountTarget::query()->where('sucursal_id', $this->corporativo->id)->where('puesto_id', $this->analista->id)->firstOrFail();
    $historial = HeadcountTargetHistorial::query()->where('sucursal_id', $this->tula->id)->firstOrFail();

    expect($target->plantilla_autorizada)->toBe(6)
        ->and($target->fuente)->toBe('excel:plazas')
        ->and($historial->valor_anterior)->toBe(4)
        ->and($historial->valor_nuevo)->toBe(5)
        ->and($historial->user_id)->toBe($this->admin->id)
        ->and($historial->motivo)->toContain('oficio 12')
        ->and($importacion->fresh()->estado)->toBe('aplicado');

    $this->assertDatabaseHas('activity_log', ['log_name' => 'headcount', 'event' => 'plazas_importadas']);
});

test('sucursal o puesto inexistente y número inválido se reportan y bloquean', function () {
    $importacion = plazasAnalizar($this, plazasArchivo([
        ['Marte', 'Gestor', 1],
        ['Tula', 'Astronauta', 1],
        ['Tula', 'Gestor', -3],
        ['Corporativo', 'Analista de Mesa de Control', 2.5],
    ]));

    $errores = collect($importacion->detalle)->pluck('errores')->flatten()->implode(' | ');

    expect($importacion->filas_error)->toBe(4)
        ->and($errores)->toContain('Marte')->toContain('Astronauta')->toContain('-3');

    $this->actingAs($this->admin)
        ->post(route('administracion.importar-datos.plazas-autorizadas.aplicar', $importacion), ['motivo' => 'Intento con errores'])
        ->assertSessionHasErrors('importacion');

    expect(HeadcountTarget::query()->count())->toBe(1)
        ->and(HeadcountTarget::query()->value('plantilla_autorizada'))->toBe(4);
});

test('un par duplicado marca ambas filas, también por puesto equivalente', function () {
    $importacion = plazasAnalizar($this, plazasArchivo([['Tula', 'Gestor', 3], ['Tula', 'Gestor Volante', 1]]));

    expect($importacion->filas_error)->toBe(2)
        ->and(collect($importacion->detalle)->pluck('errores')->flatten()->first())->toContain('Duplicado');
});

test('faltan columnas: se rechaza sin guardar la importación', function () {
    $this->actingAs($this->admin)
        ->post(route('administracion.importar-datos.plazas-autorizadas.analizar'), ['archivo' => plazasArchivo([['Tula', 3]], ['Sucursal', 'Plazas'])])
        ->assertSessionHasErrors('archivo');

    expect(ImportacionExcel::query()->where('tipo', 'plazas_autorizadas')->exists())->toBeFalse();
});

test('el mismo archivo no se aplica dos veces', function () {
    $archivo = plazasArchivo([['Tula', 'Gestor', 7]]);
    $importacion = plazasAnalizar($this, $archivo);
    $ruta = route('administracion.importar-datos.plazas-autorizadas.aplicar', $importacion);

    $this->actingAs($this->admin)->post($ruta, ['motivo' => 'Primera vez aplicada'])->assertRedirect();
    $this->actingAs($this->admin)->post($ruta, ['motivo' => 'Segunda vez aplicada'])->assertSessionHasErrors('importacion');
    $this->actingAs($this->admin)
        ->post(route('administracion.importar-datos.plazas-autorizadas.analizar'), ['archivo' => $archivo])
        ->assertSessionHasErrors('archivo');

    expect(HeadcountTargetHistorial::query()->count())->toBe(1);
});

test('si la plaza cambió desde la vista previa, no se aplica', function () {
    $importacion = plazasAnalizar($this, plazasArchivo([['Tula', 'Gestor', 9]]));
    HeadcountTarget::query()->where('sucursal_id', $this->tula->id)->update(['plantilla_autorizada' => 8]);

    $this->actingAs($this->admin)
        ->post(route('administracion.importar-datos.plazas-autorizadas.aplicar', $importacion), ['motivo' => 'Con plaza modificada'])
        ->assertSessionHasErrors('importacion');

    expect(HeadcountTarget::query()->where('sucursal_id', $this->tula->id)->value('plantilla_autorizada'))->toBe(8);
});

test('las plazas que el archivo no menciona se conservan y se listan', function () {
    $importacion = plazasAnalizar($this, plazasArchivo([['Corporativo', 'Analista de Mesa de Control', 2]]));

    expect($importacion->metadatos['no_incluidas'])->toBe([['sucursal' => 'Tula', 'puesto' => 'Gestor', 'plazas' => 4]]);
});

test('bajar plazas por debajo de las personas vigentes avisa y no oculta a nadie', function () {
    Colaborador::query()->whereNull('sucursal_principal_id')->update(['estatus' => 'inactivo']);
    Colaborador::factory()->count(3)->create(['sucursal_principal_id' => $this->tula->id, 'puesto_id' => $this->gestor->id, 'estatus' => 'activo']);

    $importacion = plazasAnalizar($this, plazasArchivo([['Tula', 'Gestor', 1]]));
    $conciliacion = $importacion->metadatos['conciliacion']['despues']['total'];

    expect($importacion->detalle[0]['avisos'][0])->toContain('quedarían 2 sin plaza')
        ->and($conciliacion['excedentes'])->toBe(2)
        ->and($conciliacion['activos'])->toBe(3);
});

test('solo super_admin entra: RH y gerente reciben 403 en todas las rutas', function (string $rol) {
    $importacion = ImportacionExcel::factory()->create(['tipo' => 'plazas_autorizadas', 'subido_por' => $this->admin->id, 'estado' => 'previsualizado', 'filas_error' => 0]);
    $usuario = User::factory()->create();
    $usuario->assignRole($rol);

    $this->actingAs($usuario)->get(route('administracion.importar-datos.plazas-autorizadas.index'))->assertForbidden();
    $this->actingAs($usuario)->get(route('administracion.importar-datos.plazas-autorizadas.plantilla'))->assertForbidden();
    $this->actingAs($usuario)->post(route('administracion.importar-datos.plazas-autorizadas.analizar'), ['archivo' => plazasArchivo([['Tula', 'Gestor', 1]])])->assertForbidden();
    $this->actingAs($usuario)->post(route('administracion.importar-datos.plazas-autorizadas.aplicar', $importacion), ['motivo' => 'Sin permiso alguno'])->assertForbidden();
})->with(['rh_admin', 'colaborador']);

test('la pantalla y el formato abren para super_admin; el formato no trae plazas', function () {
    $this->actingAs($this->admin)->get(route('administracion.importar-datos.plazas-autorizadas.index'))->assertOk();

    $respuesta = $this->actingAs($this->admin)->get(route('administracion.importar-datos.plazas-autorizadas.plantilla'))->assertOk();
    $ruta = tempnam(sys_get_temp_dir(), 'formato-').'.xlsx';
    file_put_contents($ruta, $respuesta->streamedContent());
    $hoja = IOFactory::load($ruta)->getSheet(0);

    expect($hoja->getHighestRow())->toBe(1)
        ->and($hoja->getCell('C1')->getValue())->toBe('Plazas autorizadas');
});
