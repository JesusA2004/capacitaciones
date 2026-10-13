<?php

use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use App\Services\ImportadorTrabajadores\ImportadorTrabajadoresService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| Importador de REPORTE_TRABAJADORES_VAC.xlsx (CLAUDE.md §21-36). El layout
| real tiene 3 filas antes del encabezado (fila 4): NOMBRE COMPLETO,
| SUCURSAL, FECHA INGRESO, DIAS OTORGADOS, DIAS TOMADOS, DÍAS RESTANTES,
| SALARIO DIARIO — SIN clave de empleado, RFC, CURP ni salario integrado.
*/

/**
 * Construye un .xlsx con el layout REAL del archivo oficial (3 filas antes
 * del encabezado en la fila 4) para no depender del archivo de producción
 * en la suite de pruebas.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: int|string, 4: int|string, 5: int|string, 6: int|float}>  $filas
 */
function itvArchivo(array $filas): UploadedFile
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->setCellValue('A1', 'REPORTE DE TRABAJADORES');
    $hoja->setCellValue('A2', 'Vacaciones');
    $hoja->setCellValue('A3', '');
    $hoja->fromArray(['NOMBRE COMPLETO', 'SUCURSAL', 'FECHA INGRESO', 'DIAS OTORGADOS', 'DIAS TOMADOS', 'DÍAS RESTANTES', 'SALARIO DIARIO'], null, 'A4');

    foreach ($filas as $i => $fila) {
        $hoja->fromArray($fila, null, 'A'.($i + 5));
    }

    $ruta = tempnam(sys_get_temp_dir(), 'itv-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, 'REPORTE_TRABAJADORES_VAC.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->importador = app(ImportadorTrabajadoresService::class);
    $this->actor = clUsuario('rh_admin');
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Atlacomulco', 'activo' => true]);
});

test('analizar() hace match por nombre normalizado dentro de la sucursal y propone cambio de salario, sin crear personas', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Juan', 'apellidos' => 'Pérez López',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
        'salario_diario' => 450,
    ]);

    $archivo = itvArchivo([
        ['Juan Pérez López', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 500],
    ]);

    $totalAntes = Colaborador::query()->count();
    $importacion = $this->importador->analizar($archivo, $this->actor);

    expect(Colaborador::query()->count())->toBe($totalAntes)
        ->and($importacion->total_filas)->toBe(1)
        ->and($importacion->detalle[0]['estado'])->toBe('aplicable')
        ->and($importacion->detalle[0]['colaborador_id'])->toBe($colaborador->id)
        ->and($importacion->detalle[0]['cambia_salario'])->toBeTrue()
        ->and((float) $importacion->detalle[0]['salario_diario_excel'])->toBe(500.0);
});

test('un nombre truncado/ambiguo en la misma sucursal queda para revisión, nunca se autoaplica', function () {
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Gómez Ruiz', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Gómez Ruiz', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);

    $archivo = itvArchivo([
        ['Ana Gómez Ruiz', 'Atlacomulco', '1/1/2023', 14, 0, 14, 400],
    ]);

    $importacion = $this->importador->analizar($archivo, $this->actor);

    expect($importacion->detalle[0]['estado'])->toBe('revision')
        ->and($importacion->detalle[0]['colaborador_id'])->toBeNull();
});

test('sucursal truncada conocida ("ATLACOMULC", catálogo cerrado) encuentra la sucursal real; el nombre sigue exacto', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Elena', 'apellidos' => 'Torres Díaz',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'salario_diario' => 300,
    ]);

    $archivo = itvArchivo([
        ['Elena Torres Díaz', 'ATLACOMULC', '1/1/2023', 14, 0, 14, 300],
    ]);

    $importacion = $this->importador->analizar($archivo, $this->actor);

    expect($importacion->detalle[0]['colaborador_id'])->toBe($colaborador->id)
        ->and($importacion->detalle[0]['sucursal_sistema'])->toBe('Atlacomulco');
});

test('colaborador sin 1 año de antigüedad: NUNCA se acredita ni descuenta el bloque anual, aunque el Excel traiga días otorgados/tomados', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Luis', 'apellidos' => 'Hernández Mora',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subMonths(6)->toDateString(),
        'salario_diario' => 315.04,
    ]);

    $archivo = itvArchivo([
        ['Luis Hernández Mora', 'Atlacomulco', now()->subMonths(6)->format('n/j/Y'), 12, 0, 12, 315.04],
    ]);

    $importacion = $this->importador->analizar($archivo, $this->actor);
    $fila = $importacion->detalle[0];

    expect($fila['cumple_un_anio'])->toBeFalse()
        ->and((float) $fila['ajuste_vacaciones_propuesto'])->toBe(0.0)
        ->and($fila['motivo_vacaciones_no_aplican'])->not->toBeNull()
        ->and($fila['estado'])->toBe('sin_cambios');

    $this->importador->aplicar($importacion->fresh(), $this->actor);

    expect(AjusteHistoricoVacaciones::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);
});

test('colaborador con 1+ año: los días tomados del reporte se registran como ajuste histórico negativo', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Angélica', 'apellidos' => 'González',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'salario_diario' => 333.33,
    ]);

    $archivo = itvArchivo([
        ['Angélica González', 'Atlacomulco', now()->subYears(3)->format('n/j/Y'), 20, 6, 14, 333.33],
    ]);

    $importacion = $this->importador->analizar($archivo, $this->actor);
    $fila = $importacion->detalle[0];

    expect($fila['cumple_un_anio'])->toBeTrue()
        ->and((float) $fila['ajuste_vacaciones_propuesto'])->toBe(-6.0);

    $this->importador->aplicar($importacion->fresh(), $this->actor);

    $ajuste = AjusteHistoricoVacaciones::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    expect((float) $ajuste->dias)->toBe(-6.0)
        ->and($ajuste->fuente)->toBe('importacion_excel');
});

test('aplicar() actualiza salario_diario vía CompensacionService (sueldo_mensual sincronizado) y deja historial_salarios', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Marco', 'apellidos' => 'Ibarra',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
        'salario_diario' => 300, 'sueldo_mensual' => 9000,
    ]);

    $archivo = itvArchivo([
        ['Marco Ibarra', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 350],
    ]);

    $importacion = $this->importador->analizar($archivo, $this->actor);
    $resultado = $this->importador->aplicar($importacion->fresh(), $this->actor);

    expect($resultado['filas_aplicadas'])->toBe(1)
        ->and((float) $colaborador->refresh()->salario_diario)->toBe(350.0)
        ->and((float) $colaborador->sueldo_mensual)->toBe(10500.0)
        ->and(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->where('fuente', 'importacion_excel')->exists())->toBeTrue();
});

test('el mismo archivo (mismo hash) no se puede volver a aplicar', function () {
    $archivo = itvArchivo([['Nadie Real', 'Atlacomulco', '1/1/2023', 12, 0, 12, 300]]);

    $importacion = $this->importador->analizar($archivo, $this->actor);
    $this->importador->aplicar($importacion->fresh(), $this->actor);

    $archivoRepetido = itvArchivo([['Nadie Real', 'Atlacomulco', '1/1/2023', 12, 0, 12, 300]]);

    expect(fn () => $this->importador->analizar($archivoRepetido, $this->actor))->toThrow(ValidationException::class);
});

test('aplicar() dos veces la misma importación no duplica el historial ni los ajustes (idempotente)', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Rosa', 'apellidos' => 'Campos',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'salario_diario' => 300,
    ]);

    $archivo = itvArchivo([
        ['Rosa Campos', 'Atlacomulco', now()->subYears(3)->format('n/j/Y'), 20, 5, 15, 320],
    ]);

    $importacion = $this->importador->analizar($archivo, $this->actor);
    $this->importador->aplicar($importacion->fresh(), $this->actor);

    expect(fn () => $this->importador->aplicar($importacion->fresh(), $this->actor))->toThrow(ValidationException::class);

    expect(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and(AjusteHistoricoVacaciones::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('una fila sin coincidencia de nombre queda como no_encontrado, nunca crea un colaborador nuevo', function () {
    $archivo = itvArchivo([
        ['Persona Que No Existe En Sistema', 'Atlacomulco', '1/1/2023', 12, 0, 12, 300],
    ]);

    $totalAntes = Colaborador::query()->count();
    $importacion = $this->importador->analizar($archivo, $this->actor);

    expect($importacion->detalle[0]['estado'])->toBe('no_encontrado')
        ->and(Colaborador::query()->count())->toBe($totalAntes);
});

test('un texto de sucursal fuera del catálogo cerrado de truncamientos no hace match por prefijo', function () {
    Colaborador::factory()->create(['name' => 'Elena', 'apellidos' => 'Torres', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'salario_diario' => 300]);

    $importacion = $this->importador->analizar(itvArchivo([
        ['Elena Torres', 'ATLA', '1/1/2023', 14, 0, 14, 300],
        ['Elena Torres', 'Atlacomulco Norte', '1/1/2023', 14, 0, 14, 300],
    ]), $this->actor);

    expect($importacion->detalle[0]['estado'])->toBe('no_encontrado')
        ->and($importacion->detalle[0]['colaborador_id'])->toBeNull()
        ->and($importacion->detalle[1]['estado'])->toBe('no_encontrado')
        ->and($importacion->detalle[1]['colaborador_id'])->toBeNull();
});

test('el mismo nombre exacto en OTRA sucursal no hace match (0 coincidencias → no_encontrado)', function () {
    $otra = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true]);
    Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Mora', 'sucursal_principal_id' => $otra->id, 'estatus' => 'activo']);

    $importacion = $this->importador->analizar(itvArchivo([['Luis Mora', 'Atlacomulco', '1/1/2023', 14, 0, 14, 300]]), $this->actor);

    expect($importacion->detalle[0]['estado'])->toBe('no_encontrado')
        ->and($importacion->detalle[0]['colaborador_id'])->toBeNull();
});

test('volver a previsualizar el mismo archivo reutiliza la misma importación; se aplica una sola vez', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Rosa', 'apellidos' => 'Campos',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'salario_diario' => 300,
    ]);
    $fila = ['Rosa Campos', 'Atlacomulco', now()->subYears(3)->format('n/j/Y'), 20, 5, 15, 320];

    $primera = $this->importador->analizar(itvArchivo([$fila]), $this->actor);
    $segunda = $this->importador->analizar(itvArchivo([$fila]), $this->actor);

    expect($segunda->id)->toBe($primera->id)
        ->and(ImportacionExcel::query()->count())->toBe(1);

    $this->importador->aplicar($primera->fresh(), $this->actor);

    expect(fn () => $this->importador->aplicar($segunda->fresh(), $this->actor))->toThrow(ValidationException::class)
        ->and(fn () => $this->importador->analizar(itvArchivo([$fila]), $this->actor))->toThrow(ValidationException::class);
    expect(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1)
        ->and(AjusteHistoricoVacaciones::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('RH quita un archivo analizado que ya no aplicará; uno ya aplicado se conserva como historial', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Juan', 'apellidos' => 'Pérez López',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
        'salario_diario' => 450,
    ]);
    $sinAplicar = $this->importador->analizar(itvArchivo([['Juan Pérez López', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 500]]), $this->actor);

    $this->actingAs($this->actor)->delete(route('rh.importaciones.destroy', $sinAplicar))->assertRedirect(route('rh.importaciones.index'));

    expect(ImportacionExcel::query()->whereKey($sinAplicar->id)->exists())->toBeFalse()
        ->and((float) $colaborador->refresh()->salario_diario)->toBe(450.0);

    $aplicada = $this->importador->analizar(itvArchivo([['Juan Pérez López', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 520]]), $this->actor);
    $this->importador->aplicar($aplicada, $this->actor);

    $this->actingAs($this->actor)->delete(route('rh.importaciones.destroy', $aplicada))->assertSessionHasErrors('importacion');

    expect(ImportacionExcel::query()->whereKey($aplicada->id)->exists())->toBeTrue();

    // Sin el permiso del importador, nadie quita nada.
    $this->actingAs(clUsuario('colaborador'))->delete(route('rh.importaciones.destroy', $aplicada))->assertForbidden();
});
