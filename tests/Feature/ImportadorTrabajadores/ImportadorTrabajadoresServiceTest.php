<?php

use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\ImportacionExcel;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Compensacion\CompensacionService;
use App\Services\ImportadorTrabajadores\AuditoriaFechasImportadasService;
use App\Services\ImportadorTrabajadores\ImportadorTrabajadoresService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
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
    // Importar salarios y vacaciones es solo de super_admin (2026-10-20).
    $this->actor = clUsuario('super_admin');
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

test('un texto de sucursal fuera del catálogo cerrado de truncamientos no resuelve sucursal_sistema (sin match por prefijo), pero el nombre único sigue encontrando a la persona', function () {
    // La sucursal del reporte es solo CONTEXTO para desambiguar homónimos,
    // nunca filtro obligatorio (CLAUDE.md §66-69): un texto de sucursal que
    // no está en el catálogo cerrado de truncamientos simplemente no
    // resuelve `sucursal_sistema` — nunca debe "adivinar" por prefijo/LIKE
    // — pero el nombre único de la persona sigue haciendo match igual.
    $colaborador = Colaborador::factory()->create(['name' => 'Elena', 'apellidos' => 'Torres', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'salario_diario' => 300, 'fecha_ingreso' => now()->subYears(2)->toDateString()]);

    $importacion = $this->importador->analizar(itvArchivo([
        ['Elena Torres', 'ATLA', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 300],
        ['Elena Torres', 'Atlacomulco Norte', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 300],
    ]), $this->actor);

    expect($importacion->detalle[0]['sucursal_sistema'])->toBeNull()
        ->and($importacion->detalle[0]['colaborador_id'])->toBe($colaborador->id)
        ->and($importacion->detalle[1]['sucursal_sistema'])->toBeNull()
        ->and($importacion->detalle[1]['colaborador_id'])->toBe($colaborador->id);
});

test('el mismo nombre en DOS sucursales: la del reporte desambigua y elige a la persona correcta', function () {
    $otra = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true]);
    Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Mora', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);
    $deTula = Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Mora', 'sucursal_principal_id' => $otra->id, 'estatus' => 'activo']);

    $importacion = $this->importador->analizar(itvArchivo([['Luis Mora', 'Tula', '1/1/2023', 14, 0, 14, 300]]), $this->actor);

    expect($importacion->detalle[0]['estado'])->not->toBe('revision')
        ->and($importacion->detalle[0]['colaborador_id'])->toBe($deTula->id);
});

test('el mismo nombre en dos sucursales SIN que el reporte traiga una sucursal valida queda para revision, nunca se adivina', function () {
    Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Mora', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Luis', 'apellidos' => 'Mora', 'sucursal_principal_id' => Sucursal::factory()->create(['nombre' => 'Tula'])->id, 'estatus' => 'activo']);

    $importacion = $this->importador->analizar(itvArchivo([['Luis Mora', '', '1/1/2023', 14, 0, 14, 300]]), $this->actor);

    expect($importacion->detalle[0]['estado'])->toBe('revision')
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

    // El MISMO archivo (mismos bytes) subido varias veces: generar el XLSX en
    // cada paso cambiaba su huella si cruzaba un segundo (fecha de creación
    // y fechas internas del ZIP), y la prueba fallaba de forma intermitente.
    $original = itvArchivo([$fila])->getRealPath();
    $mismoArchivo = function () use ($original): UploadedFile {
        $copia = tempnam(sys_get_temp_dir(), 'itv-').'.xlsx';
        copy($original, $copia);

        return new UploadedFile($copia, 'REPORTE_TRABAJADORES_VAC.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    };

    $primera = $this->importador->analizar($mismoArchivo(), $this->actor);
    $segunda = $this->importador->analizar($mismoArchivo(), $this->actor);

    expect($segunda->id)->toBe($primera->id)
        ->and(ImportacionExcel::query()->count())->toBe(1);

    $this->importador->aplicar($primera->fresh(), $this->actor);

    expect(fn () => $this->importador->aplicar($segunda->fresh(), $this->actor))->toThrow(ValidationException::class)
        ->and(fn () => $this->importador->analizar($mismoArchivo(), $this->actor))->toThrow(ValidationException::class);
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

test('asignarManual actualiza la fila correcta por numero de fila del Excel, no por indice del array', function () {
    // Fila 1 (Excel A5) hace match exacto; fila 2 (Excel A6) necesita
    // asignación manual — detalle[1].fila === 6, nunca detalle[6].
    Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez López', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'salario_diario' => 450, 'fecha_ingreso' => now()->subYears(2)->toDateString()]);
    $destino = Colaborador::factory()->create(['name' => 'Rosa', 'apellidos' => 'Vega', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'fecha_ingreso' => now()->subYears(2)->toDateString()]);

    $importacion = $this->importador->analizar(itvArchivo([
        ['Juan Pérez López', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 500],
        ['Nombre Que No Existe', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 300],
    ]), $this->actor);

    expect($importacion->detalle[1]['fila'])->toBe(6)
        ->and($importacion->detalle[1]['estado'])->toBe('no_encontrado');

    $this->importador->asignarManual($importacion, 6, $destino->id, $this->actor);
    $importacion->refresh();

    expect($importacion->detalle[1]['colaborador_id'])->toBe($destino->id)
        ->and($importacion->detalle[1]['match_tipo'])->toBe('manual')
        // La primera fila (Excel 5) nunca se tocó: sigue con su match exacto original.
        ->and($importacion->detalle[0]['nombre_excel'])->toBe('Juan Pérez López')
        ->and($importacion->detalle[0]['match_tipo'])->toBe('exacto');
});

test('confirmarMatch deja constancia de quien y cuando, incluso en una importacion ya aplicada', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'fecha_ingreso' => now()->subYears(2)->toDateString()]);
    $importacion = $this->importador->analizar(itvArchivo([['Nombre Inexistente', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 300]]), $this->actor);

    $this->importador->asignarManual($importacion, 5, $colaborador->id, $this->actor);
    $importacion->refresh();
    $this->importador->aplicar($importacion, $this->actor);
    $importacion->refresh();

    expect($importacion->estado)->toBe('aplicado');

    $otroRevisor = clUsuario('rh_admin');
    $this->importador->confirmarMatch($importacion, 5, $otroRevisor);
    $importacion->refresh();

    expect($importacion->detalle[0]['confirmado_por'])->toBe($otroRevisor->id)
        ->and($importacion->detalle[0]['confirmado_en'])->not->toBeNull();
});

test('analizarCorreccion bloquea si el salario del colaborador cambio despues de la importacion', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'fecha_ingreso' => now()->subYears(2)->toDateString(), 'salario_diario' => 300]);
    $importacion = $this->importador->analizar(itvArchivo([['Nombre Inexistente', 'Atlacomulco', now()->subYears(2)->format('n/j/Y'), 14, 0, 14, 315.04]]), $this->actor);

    $this->importador->asignarManual($importacion, 5, $colaborador->id, $this->actor);
    $importacion->refresh();
    $this->importador->aplicar($importacion, $this->actor);
    $importacion->refresh();

    $seguro = $this->importador->analizarCorreccion($importacion, 5);
    expect($seguro['aplica'])->toBeTrue()->and($seguro['bloqueado'])->toBeFalse();

    // RH cambia el salario DESPUÉS de la importación.
    app(CompensacionService::class)->actualizarSalario($colaborador, 333.33, null, $this->actor, ['fuente' => 'manual']);

    $bloqueado = $this->importador->analizarCorreccion($importacion, 5);
    expect($bloqueado['bloqueado'])->toBeTrue()
        ->and($bloqueado['motivo'])->toContain('cambios posteriores');
});

test('la fecha de ingreso del reporte NUNCA sobrescribe la de PEOPLE; solo queda como referencia', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Jesús', 'apellidos' => 'Prueba Fecha',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => '2025-12-01',
        'salario_diario' => 300,
    ]);

    $importacion = $this->importador->analizar(itvArchivo([
        ['Jesús Prueba Fecha', 'Atlacomulco', '01/01/2025', 12, 0, 12, 320],
    ]), $this->actor);
    $fila = $importacion->detalle[0];

    expect($fila['fecha_ingreso_people'])->toBe('2025-12-01')
        ->and($fila['fecha_ingreso_reportada_archivo'])->toBe('2025-01-01')
        ->and($fila)->not->toHaveKey('corrige_fecha_ingreso');

    $this->importador->aplicar($importacion->fresh(), $this->actor);

    expect($colaborador->refresh()->fecha_ingreso->toDateString())->toBe('2025-12-01')
        ->and((float) $colaborador->salario_diario)->toBe(320.0);
});

test('sin fecha de ingreso en PEOPLE no se toma la del reporte: queda pendiente de validación y sin vacaciones', function () {
    $colaborador = Colaborador::factory()->create([
        'name' => 'Sin', 'apellidos' => 'Fecha Ingreso',
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
        'fecha_ingreso' => null,
        'salario_diario' => 300,
    ]);

    $importacion = $this->importador->analizar(itvArchivo([
        ['Sin Fecha Ingreso', 'Atlacomulco', '15/03/2020', 20, 5, 15, 300],
    ]), $this->actor);
    $fila = $importacion->detalle[0];

    expect($fila['fecha_ingreso_pendiente'])->toBeTrue()
        ->and($fila['fecha_ingreso_reportada_archivo'])->toBe('2020-03-15')
        ->and((float) $fila['ajuste_vacaciones_propuesto'])->toBe(0.0)
        ->and($fila['motivo_vacaciones_no_aplican'])->toContain('pendiente de validación');

    $this->importador->aplicar($importacion->fresh(), $this->actor);

    expect($colaborador->refresh()->fecha_ingreso)->toBeNull()
        ->and(AjusteHistoricoVacaciones::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);
});

test('crear colaborador desde una fila no toma la fecha del archivo (solo la que RH capture validada)', function () {
    $puesto = Puesto::factory()->create();
    $importacion = $this->importador->analizar(itvArchivo([
        ['Persona Nueva Excel', 'Atlacomulco', '01/12/2025', 12, 0, 12, 300],
    ]), $this->actor);

    $sinFecha = $this->importador->registrarColaboradorDesdeFila($importacion->fresh(), 5, ['puesto_id' => $puesto->id], $this->actor);

    expect($sinFecha->fecha_ingreso)->toBeNull();
});

test('people:auditar-fechas-importadas propone RESTAURAR solo cuando hay fecha anterior sin ambigüedad', function () {
    $restaurable = Colaborador::factory()->create(['fecha_ingreso' => '2025-01-12']);
    $sinAnterior = Colaborador::factory()->create(['fecha_ingreso' => '2024-05-01']);
    $editadaDespues = Colaborador::factory()->create(['fecha_ingreso' => '2023-02-02']);

    ImportacionExcel::query()->create([
        'archivo_hash' => 'hash-auditoria', 'nombre_archivo' => 'REPORTE.xlsx', 'tipo' => 'trabajadores_vacaciones',
        'subido_por' => $this->actor->id, 'estado' => 'aplicado', 'total_filas' => 3,
        'detalle' => [
            ['fila' => 5, 'aplicado' => true, 'colaborador_id' => $restaurable->id, 'corrige_fecha_ingreso' => true, 'fecha_ingreso_anterior' => '2025-12-01', 'fecha_ingreso_excel' => '2025-01-12'],
            ['fila' => 6, 'aplicado' => true, 'colaborador_id' => $sinAnterior->id, 'corrige_fecha_ingreso' => true, 'fecha_ingreso_anterior' => null, 'fecha_ingreso_excel' => '2024-05-01'],
            ['fila' => 7, 'aplicado' => true, 'colaborador_id' => $editadaDespues->id, 'corrige_fecha_ingreso' => true, 'fecha_ingreso_anterior' => '2020-01-01', 'fecha_ingreso_excel' => '2021-01-01'],
        ],
    ]);

    $casos = collect(app(AuditoriaFechasImportadasService::class)->diagnosticar())->keyBy('fila');

    expect($casos[5]['dictamen'])->toBe('restaurar')
        ->and($casos[6]['dictamen'])->toBe('revision_manual')
        ->and($casos[7]['dictamen'])->toBe('revision_manual');

    $this->artisan('people:auditar-fechas-importadas')->assertSuccessful();
    expect($restaurable->refresh()->fecha_ingreso->toDateString())->toBe('2025-01-12');

    $this->artisan('people:auditar-fechas-importadas', ['--restaurar' => true])->assertSuccessful();

    expect($restaurable->refresh()->fecha_ingreso->toDateString())->toBe('2025-12-01')
        ->and($sinAnterior->refresh()->fecha_ingreso->toDateString())->toBe('2024-05-01')
        ->and($editadaDespues->refresh()->fecha_ingreso->toDateString())->toBe('2023-02-02');
});

test('FECHA INGRESO como fecha real de Excel (serie con formato m/d/yyyy, como el reporte real) se lee bien; el texto dd/mm también', function () {
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Serie Uno', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'fecha_ingreso' => '2020-01-01', 'salario_diario' => 300]);
    Colaborador::factory()->create(['name' => 'Beto', 'apellidos' => 'Serie Dos', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'fecha_ingreso' => '2020-01-01', 'salario_diario' => 300]);
    Colaborador::factory()->create(['name' => 'Ceci', 'apellidos' => 'Texto Tres', 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo', 'fecha_ingreso' => '2020-01-01', 'salario_diario' => 300]);

    $archivo = itvArchivo([
        ['Ana Serie Uno', 'ATLACOMULC', '', 12, 0, 12, 320],
        ['Beto Serie Dos', 'ATLACOMULC', '', 12, 0, 12, 320],
        ['Ceci Texto Tres', 'ATLACOMULC', '29/06/2021', 12, 0, 12, 320],
    ]);

    // Igual que el archivo real: número de serie con formato de fecha
    // estadounidense. 5 de marzo (ambigua al leerla como dd/mm) y 17 de
    // junio (día > 12: nula al leerla como dd/mm).
    $libro = IOFactory::load($archivo->getRealPath());
    $hoja = $libro->getActiveSheet();
    $hoja->setCellValue('C5', FechaExcel::PHPToExcel(new DateTime('2026-03-05')));
    $hoja->setCellValue('C6', FechaExcel::PHPToExcel(new DateTime('2021-06-17')));
    $hoja->getStyle('C5:C6')->getNumberFormat()->setFormatCode('m/d/yyyy');
    (new Xlsx($libro))->save($archivo->getRealPath());

    $detalle = $this->importador->analizar($archivo, $this->actor)->detalle;

    expect(array_column($detalle, 'fecha_ingreso_reportada_archivo'))->toBe(['2026-03-05', '2021-06-17', '2021-06-29']);
});
