<?php

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Ver CLAUDE.md ("cierre real MR. LANA PEOPLE"), sección 16: esta migración
 * de storage es destructiva en producción (mueve/borra archivos reales en
 * el NAS), así que a diferencia de la mayoría de features del repo, aquí sí
 * vale la pena blindar cada caso con Storage::fake('nas') antes de tocar el
 * NAS real.
 */
function colaboradorConSucursal(string $sucursal = 'Cuernavaca', string $empresa = 'MR LANA', array $atributos = []): User
{
    $empresaModelo = Empresa::factory()->create(['nombre' => $empresa]);
    $sucursalModelo = Sucursal::factory()->create(['empresa_id' => $empresaModelo->id, 'nombre' => $sucursal]);

    return User::factory()->create(array_merge([
        'sucursal_principal_id' => $sucursalModelo->id,
        'numero_empleado' => '00125',
        'name' => 'Juan',
        'apellidos' => 'Perez',
    ], $atributos));
}

function archivoFalso(string $nombre = 'documento.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($nombre, 10, 'application/pdf');
}

beforeEach(function () {
    Storage::fake('nas');
});

test('A: la primera subida crea la carpeta legible y persiste la ruta base del colaborador', function () {
    $colaborador = colaboradorConSucursal();
    $tipo = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento']);
    $storage = app(DocumentoStorageService::class);

    $documento = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);

    expect($documento->path)->toBe('expedientes/MR LANA/Cuernavaca/00125 - Juan Perez/Acta de nacimiento - v1.pdf')
        ->and($documento->version)->toBe(1)
        ->and($colaborador->fresh()->expediente_storage_path)->toBe('expedientes/MR LANA/Cuernavaca/00125 - Juan Perez');

    Storage::disk('nas')->assertExists($documento->path);
});

test('B: una segunda version conserva la primera intacta', function () {
    $colaborador = colaboradorConSucursal();
    $tipo = DocumentType::factory()->create(['nombre' => 'Comprobante de domicilio']);
    $storage = app(DocumentoStorageService::class);

    $v1 = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);
    $v2 = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);

    expect($v2->version)->toBe(2)
        ->and($v2->previous_version_id)->toBe($v1->id)
        ->and($v2->path)->toContain('Comprobante de domicilio - v2.pdf');

    Storage::disk('nas')->assertExists($v1->path);
    Storage::disk('nas')->assertExists($v2->path);

    expect($v1->fresh()->status)->toBe(EstadoDocumento::Archivado);
});

test('C: dos colaboradores homonimos con distinto numero de empleado no colisionan', function () {
    $empresa = Empresa::factory()->create(['nombre' => 'MR LANA']);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Cuernavaca']);

    $juanA = User::factory()->create(['sucursal_principal_id' => $sucursal->id, 'numero_empleado' => '00125', 'name' => 'Juan', 'apellidos' => 'Perez']);
    $juanB = User::factory()->create(['sucursal_principal_id' => $sucursal->id, 'numero_empleado' => '00473', 'name' => 'Juan', 'apellidos' => 'Perez']);

    $tipo = DocumentType::factory()->create(['nombre' => 'INE']);
    $storage = app(DocumentoStorageService::class);

    $docA = $storage->subirVersion($juanA, $tipo, archivoFalso(), $juanA->id);
    $docB = $storage->subirVersion($juanB, $tipo, archivoFalso(), $juanB->id);

    expect($docA->path)->not->toBe($docB->path)
        ->and($docA->path)->toContain('00125 - Juan Perez')
        ->and($docB->path)->toContain('00473 - Juan Perez');
});

test('D: un colaborador sin numero de empleado usa el placeholder SIN-NUMERO-{id}', function () {
    $colaborador = colaboradorConSucursal(atributos: ['numero_empleado' => null]);
    $tipo = DocumentType::factory()->create(['nombre' => 'RFC']);
    $storage = app(DocumentoStorageService::class);

    $documento = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);

    expect($documento->path)->toContain("SIN-NUMERO-{$colaborador->id} - Juan Perez");
});

test('E: cambiar el nombre despues de la v1 no mueve la v2 de carpeta', function () {
    $colaborador = colaboradorConSucursal();
    $tipo = DocumentType::factory()->create(['nombre' => 'CURP']);
    $storage = app(DocumentoStorageService::class);

    $v1 = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);
    $rutaOriginal = dirname($v1->path);

    $colaborador->update(['apellidos' => 'Perez Hernandez']);

    $v2 = $storage->subirVersion($colaborador->fresh(), $tipo, archivoFalso(), $colaborador->id);

    expect(dirname($v2->path))->toBe($rutaOriginal)
        ->and($v2->path)->toContain('00125 - Juan Perez/');
});

test('F: cambiar de sucursal despues de la v1 no parte el expediente', function () {
    $colaborador = colaboradorConSucursal();
    $tipo = DocumentType::factory()->create(['nombre' => 'NSS']);
    $storage = app(DocumentoStorageService::class);

    $v1 = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);
    $rutaOriginal = dirname($v1->path);

    $nuevaSucursal = Sucursal::factory()->create(['nombre' => 'Lerma']);
    $colaborador->update(['sucursal_principal_id' => $nuevaSucursal->id]);

    $v2 = $storage->subirVersion($colaborador->fresh(), $tipo, archivoFalso(), $colaborador->id);

    expect(dirname($v2->path))->toBe($rutaOriginal)
        ->and($v2->path)->not->toContain('Lerma');
});

test('G: si falla la creacion en BD, el archivo recien guardado se elimina', function () {
    $colaborador = colaboradorConSucursal();
    $tipo = DocumentType::factory()->create(['nombre' => 'Contrato laboral']);
    $storage = app(DocumentoStorageService::class);

    // Fuerza un fallo real de BD: la fila document_type_id ya no existe al
    // momento del INSERT (violacion de llave foranea), sin documentos
    // previos que bloqueen el delete.
    $tipoId = $tipo->id;
    $tipo->delete();
    $tipoFantasma = DocumentType::factory()->make();
    $tipoFantasma->id = $tipoId;
    $tipoFantasma->exists = true;

    expect(fn () => $storage->subirVersion($colaborador, $tipoFantasma, archivoFalso(), $colaborador->id))
        ->toThrow(QueryException::class);

    Storage::disk('nas')->assertDirectoryEmpty('expedientes');
});

test('H: subir un archivo con destino ya existente nunca lo sobrescribe', function () {
    $colaborador = colaboradorConSucursal();
    $storage = app(DocumentoStorageService::class);

    $ruta = $storage->rutaDocumento($colaborador, DocumentType::factory()->create(), 1, 'pdf');
    Storage::disk('nas')->put($ruta, 'contenido-original');

    expect(fn () => $storage->guardar(archivoFalso(), $ruta))->toThrow(RuntimeException::class);

    expect(Storage::disk('nas')->get($ruta))->toBe('contenido-original');
});

test('la version se calcula sobre el maximo historico, nunca reutiliza un numero ya usado', function () {
    $colaborador = colaboradorConSucursal();
    $tipo = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento']);
    $storage = app(DocumentoStorageService::class);

    $v1 = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);
    $v2 = $storage->subirVersion($colaborador, $tipo, archivoFalso(), $colaborador->id);

    // v2 se borra logicamente (soft delete) - la version 2 "desaparece" del
    // listado normal pero el archivo v2 real pudo seguir vivo en el NAS.
    $v2->delete();

    $v3 = $storage->subirVersion($colaborador->fresh(), $tipo, archivoFalso(), $colaborador->id);

    expect($v3->version)->toBe(3)
        ->and($v3->path)->toContain('Acta de nacimiento - v3.pdf');
});
