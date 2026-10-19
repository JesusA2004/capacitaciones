<?php

use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Headcount\HeadcountImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| Lector del Excel de headcount (HEADCOUNT GENERAL MR LANA 16-10-2026):
| fixture sintético con las mismas irregularidades del archivo real —
| bloques pegados sin renglón en blanco, «Totales:» en B/C/G, una fila con
| número pero sin modalidad y «TULA DE ALLENDE» por «Tula».
*/

/**
 * @param  array<int, array{0: string, 1: int|null}>  $filas  modalidad => autorizada
 */
function hcBloque(Worksheet $hoja, string $columna, int $fila, string $sucursal, array $filas, string $columnaTotales): int
{
    $cols = $columna === 'A' ? ['A', 'B', 'C', 'D'] : ['F', 'G', 'H', 'I'];
    $hoja->setCellValue($cols[0].$fila, $sucursal);
    $hoja->setCellValue($cols[0].($fila + 1), 'MODALIDAD');
    $hoja->setCellValue($cols[1].($fila + 1), 'PLANTILLA AUTORIZADA');
    $hoja->setCellValue($cols[2].($fila + 1), 'PLANTILLA ACTUAL');
    $hoja->setCellValue($cols[3].($fila + 1), 'VACANTES');

    $r = $fila + 2;
    foreach ($filas as [$modalidad, $autorizada]) {
        $hoja->setCellValue($cols[0].$r, $modalidad);
        if ($autorizada !== null) {
            $hoja->setCellValue($cols[1].$r, $autorizada);
            $hoja->setCellValue($cols[2].$r, 0);
        }
        $r++;
    }
    $hoja->setCellValue($columnaTotales.$r, 'Totales:');

    return $r + 1;
}

function hcArchivo(callable $llenar): string
{
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->setTitle('Q1');
    $libro->getActiveSheet()->setCellValue('A2', 'PLANTILLA Q1');
    $llenar($libro->getActiveSheet());

    $ruta = tempnam(sys_get_temp_dir(), 'hc-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return $ruta;
}

beforeEach(function () {
    foreach (['Ixtlahuaca', 'Atlacomulco', 'Tula', 'Tenango del Valle'] as $nombre) {
        Sucursal::factory()->create(['nombre' => $nombre]);
    }
    foreach (['Gestor', 'Gestor Volante', 'Coordinadora de Sucursal', 'Gerente de Sucursal', 'Subgerente', 'Gestor grupal'] as $nombre) {
        Puesto::factory()->create(['nombre' => $nombre]);
    }
});

function hcPlantilla(string $sucursal, string $puesto): ?int
{
    return HeadcountTarget::query()
        ->where('sucursal_id', Sucursal::query()->where('nombre', $sucursal)->value('id'))
        ->where('puesto_id', Puesto::query()->where('nombre', $puesto)->value('id'))
        ->value('plantilla_autorizada');
}

test('dos bloques pegados (sin renglón en blanco y «Totales:» en la columna B) se leen como dos sucursales', function () {
    $ruta = hcArchivo(function (Worksheet $hoja) {
        // Igual que la hoja Q1 real: ATLACOMULCO empieza justo después de «Totales:» de IXTLAHUACA.
        $siguiente = hcBloque($hoja, 'A', 6, 'IXTLAHUACA', [['GESTOR DE RUTA', 6], ['GESTOR VOLANTE', 1], ['GERENTE ', 1], ['INCAPACITADOS', 0]], 'B');
        hcBloque($hoja, 'A', $siguiente, 'ATLACOMULCO ', [['GESTOR DE RUTA', 4], ['GESTOR VOLANTE', 0], ['SUBGERENTE', 1]], 'C');
    });

    $resultado = app(HeadcountImportService::class)->importar($ruta, null);

    expect(hcPlantilla('Ixtlahuaca', 'Gestor'))->toBe(6)
        ->and(hcPlantilla('Ixtlahuaca', 'Gerente de Sucursal'))->toBe(1)
        ->and(hcPlantilla('Ixtlahuaca', 'Subgerente'))->toBeNull()
        ->and(hcPlantilla('Atlacomulco', 'Gestor'))->toBe(4)
        ->and(hcPlantilla('Atlacomulco', 'Gestor Volante'))->toBe(0)
        ->and(hcPlantilla('Atlacomulco', 'Subgerente'))->toBe(1)
        ->and($resultado['creados'])->toBe(6)
        ->and($resultado['sucursales_sin_match'])->toBe([])
        ->and($resultado['puestos_sin_match'])->toBe([])
        ->and($resultado['filas_incompletas'])->toBe([]);
});

test('«TULA DE ALLENDE» entra a «Tula» por el alias confirmado; GESTOR GRUPAL va al puesto Gestor grupal', function () {
    $ruta = hcArchivo(function (Worksheet $hoja) {
        hcBloque($hoja, 'F', 6, 'TULA DE ALLENDE', [['GESTORES DE RUTA', 6], ['COORDINADORA DE SUCURSAL', 1], ['GESTOR GRUPAL', 2]], 'G');
    });

    $resultado = app(HeadcountImportService::class)->importar($ruta, null);

    expect($resultado['sucursales_sin_match'])->toBe([])
        ->and(hcPlantilla('Tula', 'Gestor'))->toBe(6)
        ->and(hcPlantilla('Tula', 'Coordinadora de Sucursal'))->toBe(1)
        ->and(hcPlantilla('Tula', 'Gestor grupal'))->toBe(2);
});

test('una fila con número pero sin modalidad no se adivina: se reporta como incompleta y el resto del bloque sí se lee', function () {
    $ruta = hcArchivo(function (Worksheet $hoja) {
        hcBloque($hoja, 'F', 6, 'TENANGO DEL VALLE', [[' ', 7], ['GESTOR VOLANTE', 1], ['GERENTE', null], ['SUBGERENTE', 1], ['INCAPACITADOS', null]], 'G');
    });

    $resultado = app(HeadcountImportService::class)->importar($ruta, null);

    expect(hcPlantilla('Tenango del Valle', 'Gestor'))->toBeNull()
        ->and(hcPlantilla('Tenango del Valle', 'Gestor Volante'))->toBe(1)
        ->and(hcPlantilla('Tenango del Valle', 'Gerente de Sucursal'))->toBeNull()
        ->and(hcPlantilla('Tenango del Valle', 'Subgerente'))->toBe(1)
        ->and($resultado['filas_incompletas'])->toHaveCount(2)
        ->and($resultado['filas_incompletas'][0])->toContain('celda F8 vacía')->toContain('plantilla autorizada 7')->toContain('TENANGO DEL VALLE')
        ->and($resultado['filas_incompletas'][1])->toContain('GERENTE (celda F10) sin número');
});

test('el mismo par en dos hojas con distinto valor se reporta como conflicto y conserva el primero', function () {
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->setTitle('Q1');
    hcBloque($libro->getActiveSheet(), 'A', 6, 'IXTLAHUACA', [['GESTOR VOLANTE', 1]], 'B');
    $segunda = $libro->createSheet()->setTitle('SUCURSALES ');
    hcBloque($segunda, 'A', 6, 'IXTLAHUACA', [['GESTOR VOLANTE', 0]], 'B');
    $ruta = tempnam(sys_get_temp_dir(), 'hc-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    $resultado = app(HeadcountImportService::class)->importar($ruta, null);

    expect(hcPlantilla('Ixtlahuaca', 'Gestor Volante'))->toBe(1)
        ->and($resultado['conflictos'])->toHaveCount(1)
        ->and($resultado['conflictos'][0])->toContain('1 vs 0');
});
