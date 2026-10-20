<?php

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use App\Services\ImportadorContactosEmergencia\ImportadorContactosEmergenciaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| Importador del Excel de contactos de emergencia (export de Google Forms):
| encabezados por texto normalizado, match por nombre + sucursal, vista previa
| sin escribir, confirmar idempotente, «No» explícito y permisos.
*/

/** Encabezados tal cual los manda el negocio (fila 1). */
const ICE_ENCABEZADOS = [
    'Nombre completo del colaborador (no apodos, ni diminutivos)',
    'Sucursal',
    'Número de teléfono personal del colaborador',
    '¿Tienes alguna condición medica crónica?,  en caso de que sí menciona cuál.',
    '¿Tienes alguna alergia?(medicamentos, alimentos o picaduras), en caso de que sí menciona cuál.',
    'Nombre de contacto de emergencia',
    'Parentesco',
    'Número telefónico del contacto de emergencia',
    'Dirección del contacto de emergencia',
];

/**
 * @param  list<list<mixed>>  $filas
 * @param  list<string>|null  $encabezados
 */
function iceArchivo(array $filas, ?array $encabezados = null, string $nombre = 'Contactos.xlsx'): UploadedFile
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    $hoja->fromArray($encabezados ?? ICE_ENCABEZADOS, null, 'A1');

    foreach ($filas as $i => $fila) {
        $hoja->fromArray($fila, null, 'A'.($i + 2));
    }

    $ruta = tempnam(sys_get_temp_dir(), 'ice-').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function iceFilaLuis(): array
{
    return ['Luis Carlos Lovera Casimiro', 'Atlacomulco', '553 932 9604', 'No', 'no', 'kimberly gonzález baltazar', 'esposa', '712 184 2747', 'Santa María Citendeje'];
}

/**
 * @return array<string, mixed>
 */
function iceFila(ImportacionExcel $importacion, int $fila): array
{
    return collect($importacion->detalle)->firstWhere('fila', $fila);
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->importador = app(ImportadorContactosEmergenciaService::class);
    // Importar es solo de super_admin (2026-10-20); RH ya no lo ve.
    $this->actor = clUsuario('super_admin');
    $this->atlacomulco = Sucursal::factory()->create(['nombre' => 'Atlacomulco', 'activo' => true]);
    $this->toluca = Sucursal::factory()->create(['nombre' => 'Toluca', 'activo' => true]);
    $this->luis = Colaborador::factory()->create([
        'name' => 'Luis Carlos', 'apellidos' => 'Lovera Casimiro',
        'sucursal_principal_id' => $this->atlacomulco->id, 'estatus' => 'activo',
        'telefono' => null, 'contacto_emergencia_nombre' => null, 'contacto_emergencia_parentesco' => null,
        'contacto_emergencia_telefono' => null, 'contacto_emergencia_direccion' => null,
    ]);
});

test('detecta los encabezados aunque traigan columnas extra, otro orden, espacios dobles y acentos distintos', function () {
    $encabezados = [
        'Marca temporal',
        'Direccion  del contacto de EMERGENCIA',
        'NOMBRE COMPLETO DEL COLABORADOR',
        'sucursal',
        'Numero de telefono personal del colaborador',
        '¿Tienes alguna condicion medica cronica?',
        '¿Tienes alguna alergia? (medicamentos, alimentos o picaduras)',
        'Nombre de contacto de emergencia ',
        'PARENTESCO',
        'Numero telefonico del contacto de emergencia',
    ];

    $importacion = $this->importador->analizar(iceArchivo([
        ['2026/10/01 10:00', 'Santa María Citendeje', 'Luis Carlos Lovera Casimiro', 'Atlacomulco', '553 932 9604', 'Diabetes', 'Penicilina', 'Kimberly González Baltazar', 'Esposa', '712 184 2747'],
    ], $encabezados), $this->actor);

    $fila = iceFila($importacion, 2);

    expect($importacion->metadatos['columnas'])->toHaveCount(9)
        ->and($importacion->metadatos['columnas_ignoradas'])->toBe(['Marca temporal'])
        ->and($fila['estado'])->toBe('aplicable')
        ->and($fila['colaborador_id'])->toBe($this->luis->id)
        ->and($fila['cambios'])->toHaveKeys(['telefono', 'contacto_emergencia_nombre', 'contacto_emergencia_direccion', 'condicion_medica', 'alergias'])
        ->and($fila['cambios']['contacto_emergencia_direccion']['despues'])->toBe('Santa María Citendeje');
});

test('si falta una columna obligatoria responde con un error claro que la nombra', function () {
    $sinParentesco = array_values(array_filter(ICE_ENCABEZADOS, fn (string $e): bool => $e !== 'Parentesco'));

    try {
        $this->importador->analizar(iceArchivo([], $sinParentesco), $this->actor);
        $this->fail('Debió rechazar el archivo.');
    } catch (ValidationException $e) {
        expect($e->errors()['archivo'][0])->toContain('«Parentesco»');
    }
});

test('la vista previa no guarda nada en el colaborador ni en datos médicos', function () {
    $this->importador->analizar(iceArchivo([iceFilaLuis()]), $this->actor);

    $this->luis->refresh();

    expect($this->luis->telefono)->toBeNull()
        ->and($this->luis->contacto_emergencia_nombre)->toBeNull()
        ->and(ColaboradorDatosMedicos::query()->count())->toBe(0)
        ->and(ImportacionExcel::query()->first()?->estado)->toBe('previsualizado');
});

test('confirmar guarda normalizado, «No» explícito, y una segunda carga del mismo archivo sale sin cambios', function () {
    $archivo = iceArchivo([iceFilaLuis()]);
    // Copia byte a byte (el .xlsx guarda la hora de creación: regenerarlo
    // cambiaría el hash y ya no sería «el mismo archivo»).
    $copia = tempnam(sys_get_temp_dir(), 'ice-copia-').'.xlsx';
    copy($archivo->getRealPath(), $copia);

    $importacion = $this->importador->analizar($archivo, $this->actor);
    $resumen = $this->importador->aplicar($importacion, $this->actor);

    $this->luis->refresh();
    $medicos = ColaboradorDatosMedicos::query()->where('colaborador_id', $this->luis->id)->first();

    expect($resumen['actualizados'])->toBe(1)
        ->and($this->luis->telefono)->toBe('5539329604')
        ->and($this->luis->contacto_emergencia_nombre)->toBe('Kimberly González Baltazar')
        ->and($this->luis->contacto_emergencia_parentesco)->toBe('Esposa')
        ->and($this->luis->contacto_emergencia_telefono)->toBe('7121842747')
        ->and($this->luis->contacto_emergencia_direccion)->toBe('Santa María Citendeje')
        ->and($medicos?->condicion_medica)->toBe('No')
        ->and($medicos?->alergias)->toBe('No')
        ->and($importacion->refresh()->estado)->toBe('aplicado')
        ->and($importacion->metadatos['resumen'])->toBe(['actualizados' => 1, 'sin_cambios' => 0, 'omitidos' => 0])
        ->and($importacion->aplicado_por)->toBe($this->actor->id);

    // Detalle sin datos médicos en claro.
    expect(json_encode($importacion->detalle))->not->toContain('"despues":"No"');

    // Mismo archivo otra vez: nueva vista previa, todo sin cambios.
    $segunda = $this->importador->analizar(new UploadedFile($copia, 'Contactos.xlsx', null, null, true), $this->actor);
    expect($segunda->id)->not->toBe($importacion->id)
        ->and(iceFila($segunda, 2)['estado'])->toBe('sin_cambios')
        ->and($segunda->metadatos['reanalisis_de'])->toBe($importacion->id);

    $resumen2 = $this->importador->aplicar($segunda, $this->actor);
    expect($resumen2['actualizados'])->toBe(0)->and($resumen2['sin_cambios'])->toBe(1);

    // Y una importación aplicada no se vuelve a aplicar.
    expect(fn () => $this->importador->aplicar($importacion->refresh(), $this->actor))->toThrow(ValidationException::class);
});

test('match por nombre + sucursal resuelve homónimos; ambiguo y no encontrado se reportan y se asignan a mano', function () {
    $mismoNombreAtla = Colaborador::factory()->create(['name' => 'Eliot', 'apellidos' => 'Serrano Sánchez', 'sucursal_principal_id' => $this->atlacomulco->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Eliot', 'apellidos' => 'Serrano Sanchez', 'sucursal_principal_id' => $this->toluca->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Ruiz Paz', 'sucursal_principal_id' => $this->atlacomulco->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Ruiz Paz', 'sucursal_principal_id' => $this->atlacomulco->id, 'estatus' => 'activo']);
    $angel = Colaborador::factory()->create(['name' => 'Luis Ángel', 'apellidos' => 'Cruz Velázquez', 'sucursal_principal_id' => $this->atlacomulco->id, 'estatus' => 'activo', 'contacto_emergencia_nombre' => null]);

    $importacion = $this->importador->analizar(iceArchivo([
        ['ELIOT  serrano sanchez', 'Atlacomulco', '712 225 6115', 'No', 'No', 'Luís Serrano Mendoza', 'Padre', '712 163 0404', 'Buenavista Casa Blanca 1, San José del Rincón, México.'],
        ['Ana Ruiz Paz', 'Atlacomulco', '7120000000', 'No', 'No', 'Pedro Ruiz', 'Padre', '7121111111', 'Centro'],
        ['Luis Angel Cruz V.', 'Atlacomulco', '720 560 5059', 'No', 'No', 'Eulalia Velázquez Jiménez', 'Mamá', '712 343 5846', '5 de Mayo #510, Barrio de San Juan, Jocotitlán, México'],
    ]), $this->actor);

    expect(iceFila($importacion, 2)['colaborador_id'])->toBe($mismoNombreAtla->id)
        ->and(iceFila($importacion, 2)['estado'])->toBe('aplicable')
        ->and(iceFila($importacion, 3)['estado'])->toBe('revision')
        ->and(iceFila($importacion, 3)['sugerencias'])->toHaveCount(2)
        ->and(iceFila($importacion, 4)['estado'])->toBe('no_encontrado');

    // RH elige a mano al de la fila no encontrada (buscador sin mínimo de letras).
    $this->actingAs($this->actor)
        ->getJson(route('rh.contactos-emergencia.colaboradores', ['q' => '']))
        ->assertOk()
        ->assertJsonFragment(['id' => $angel->id]);

    $this->actingAs($this->actor)
        ->post(route('rh.contactos-emergencia.filas.asignar', ['importacion' => $importacion->id, 'fila' => 4]), ['colaborador_id' => $angel->id])
        ->assertSessionHasNoErrors();

    $fila = iceFila($importacion->refresh(), 4);
    expect($fila['estado'])->toBe('aplicable')
        ->and($fila['match'])->toBe('manual')
        ->and($fila['cambios']['contacto_emergencia_parentesco']['despues'])->toBe('Mamá');

    // Confirmar solo aplica las filas válidas; la ambigua queda omitida con motivo.
    $resumen = $this->importador->aplicar($importacion->refresh(), $this->actor);
    expect($resumen['actualizados'])->toBe(2)
        ->and($resumen['omitidos'])->toBe(1)
        ->and(iceFila($importacion->refresh(), 3)['motivo_resultado'])->toContain('ambiguo')
        ->and($angel->refresh()->contacto_emergencia_nombre)->toBe('Eulalia Velázquez Jiménez');
});

test('un teléfono que no tiene 10 dígitos solo se guarda si RH lo confirma', function () {
    $fila = iceFilaLuis();
    $fila[2] = '55 39 32';

    $importacion = $this->importador->analizar(iceArchivo([$fila]), $this->actor);
    expect(iceFila($importacion, 2)['requiere_confirmacion'])->toBeTrue()
        ->and(iceFila($importacion, 2)['advertencias'][0])->toContain('10 dígitos');

    $this->importador->aplicar($importacion, $this->actor);
    expect($this->luis->refresh()->telefono)->toBeNull()
        ->and($this->luis->contacto_emergencia_telefono)->toBe('7121842747');

    $otra = $this->importador->analizar(iceArchivo([$fila], null, 'Otra.xlsx'), $this->actor);
    $this->importador->aplicar($otra, $this->actor, null, [2]);
    expect($this->luis->refresh()->telefono)->toBe('55 39 32');
});

test('una celda vacía nunca borra lo que ya tiene el colaborador', function () {
    $this->luis->update(['contacto_emergencia_direccion' => 'Domicilio previo']);
    $fila = iceFilaLuis();
    $fila[8] = '';

    $importacion = $this->importador->analizar(iceArchivo([$fila]), $this->actor);
    $this->importador->aplicar($importacion, $this->actor);

    expect($this->luis->refresh()->contacto_emergencia_direccion)->toBe('Domicilio previo');
});

test('nadie carga sus propios datos por esta vía', function () {
    $propio = $this->actor->colaborador;
    $propio->update(['name' => 'Rosa', 'apellidos' => 'Mendoza Luna', 'sucursal_principal_id' => $this->atlacomulco->id, 'estatus' => 'activo']);

    $importacion = $this->importador->analizar(iceArchivo([
        ['Rosa Mendoza Luna', 'Atlacomulco', '7121234567', 'No', 'No', 'Juan Luna', 'Hermano', '7127654321', 'Centro'],
    ]), $this->actor);

    expect(iceFila($importacion, 2)['estado'])->toBe('omitido')
        ->and(iceFila($importacion, 2)['motivo'])->toContain('propio');

    expect(fn () => $this->importador->asignarColaborador($importacion, 2, $propio->id, $this->actor))->toThrow(ValidationException::class);
});

test('sin contactos_emergencia.importar (colaborador, rh_admin, rh_auxiliar) no se puede ver, subir, descargar plantilla ni confirmar (403)', function () {
    $importacion = $this->importador->analizar(iceArchivo([iceFilaLuis()]), $this->actor);

    foreach (['colaborador', 'rh_admin', 'rh_auxiliar'] as $rol) {
        $sinPermiso = clUsuario($rol);

        $this->actingAs($sinPermiso)->get(route('rh.contactos-emergencia.index'))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('rh.contactos-emergencia.show', $importacion))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('rh.contactos-emergencia.plantilla'))->assertForbidden();
        $this->actingAs($sinPermiso)->post(route('rh.contactos-emergencia.analizar'), ['archivo' => iceArchivo([iceFilaLuis()])])->assertForbidden();
        $this->actingAs($sinPermiso)->post(route('rh.contactos-emergencia.aplicar', $importacion))->assertForbidden();
        $this->actingAs($sinPermiso)->getJson(route('rh.contactos-emergencia.colaboradores'))->assertForbidden();
        $this->actingAs($sinPermiso)->delete(route('rh.contactos-emergencia.destroy', $importacion))->assertForbidden();
    }

    expect($this->luis->refresh()->telefono)->toBeNull();
});

test('la plantilla vacía trae exactamente los nueve encabezados oficiales', function () {
    $respuesta = $this->actingAs($this->actor)->get(route('rh.contactos-emergencia.plantilla'))->assertOk();

    $ruta = tempnam(sys_get_temp_dir(), 'ice-plantilla-').'.xlsx';
    file_put_contents($ruta, $respuesta->getContent());
    $encabezados = IOFactory::load($ruta)->getActiveSheet()->rangeToArray('A1:I1')[0];

    expect($encabezados)->toBe(ICE_ENCABEZADOS);
});
