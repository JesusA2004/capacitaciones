<?php

use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteRelocationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

if (! function_exists('subidorId')) {
    function subidorId(): int
    {
        return User::factory()->create()->id;
    }
}

beforeEach(function () {
    Storage::fake('nas');
});

test('relocalizar mueve todos los documentos y la foto a la nueva carpeta, verificado por hash', function () {
    $empresa = Empresa::factory()->create(['nombre' => 'MR LANA']);
    $sucursalVieja = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Cuernavaca']);
    $sucursalNueva = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Lerma']);

    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursalVieja->id,
        'numero_empleado' => '00125',
        'name' => 'Juan',
        'apellidos' => 'Perez',
    ]);

    $storage = app(DocumentoStorageService::class);
    $tipoActa = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento']);
    $tipoCurp = DocumentType::factory()->create(['nombre' => 'CURP']);

    $acta = $storage->subirVersion($colaborador, $tipoActa, UploadedFile::fake()->create('acta.pdf', 5), subidorId());
    $curpV1 = $storage->subirVersion($colaborador, $tipoCurp, UploadedFile::fake()->create('curp.pdf', 5), subidorId());
    $curpV2 = $storage->subirVersion($colaborador, $tipoCurp, UploadedFile::fake()->create('curp.pdf', 5), subidorId());

    $rutaFoto = $storage->rutaFoto($colaborador, 'jpg');
    Storage::disk('nas')->put($rutaFoto, 'contenido-foto');
    $colaborador->update(['foto_path' => $rutaFoto]);

    $rutaVieja = $colaborador->fresh()->expediente_storage_path;

    // Cambia de sucursal: DocumentoStorageService seguiria usando la ruta
    // vieja para nuevas subidas (ver DocumentoStorageServiceTest, caso F).
    // Ahora se pide relocalizar explicitamente.
    $colaborador->update(['sucursal_principal_id' => $sucursalNueva->id]);

    $servicio = app(ExpedienteRelocationService::class);
    $resultado = $servicio->relocalizar($colaborador->fresh());

    expect($resultado['movido'])->toBeTrue()
        ->and($resultado['archivos'])->toBe(4)
        ->and($resultado['ruta_anterior'])->toBe($rutaVieja)
        ->and($resultado['ruta_nueva'])->toContain('Lerma');

    $colaborador->refresh();
    expect($colaborador->expediente_storage_path)->toBe($resultado['ruta_nueva'])
        ->and($colaborador->foto_path)->toStartWith($resultado['ruta_nueva']);

    foreach ([$acta, $curpV1, $curpV2] as $documento) {
        $documento->refresh();
        expect($documento->path)->toStartWith($resultado['ruta_nueva']);
        Storage::disk('nas')->assertExists($documento->path);
    }

    // La carpeta vieja completa (incluida la de sucursal, si quedo vacia)
    // debe haber desaparecido: nada de expediente partido en dos carpetas.
    Storage::disk('nas')->assertMissing($rutaVieja.'/Acta de nacimiento - v1.pdf');
    expect(Storage::disk('nas')->exists($rutaVieja))->toBeFalse();
});

test('relocalizar no hace nada si la ruta calculada no cambio', function () {
    $empresa = Empresa::factory()->create(['nombre' => 'MR LANA']);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Cuernavaca']);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'numero_empleado' => '00125', 'name' => 'Juan', 'apellidos' => 'Perez']);

    $storage = app(DocumentoStorageService::class);
    $tipo = DocumentType::factory()->create();
    $storage->subirVersion($colaborador, $tipo, UploadedFile::fake()->create('doc.pdf', 5), subidorId());

    $servicio = app(ExpedienteRelocationService::class);
    $resultado = $servicio->relocalizar($colaborador->fresh());

    expect($resultado['movido'])->toBeFalse();
});

test('relocalizar sin ruta persistida no hace nada', function () {
    $colaborador = Colaborador::factory()->create();

    $servicio = app(ExpedienteRelocationService::class);
    $resultado = $servicio->relocalizar($colaborador);

    expect($resultado['movido'])->toBeFalse()
        ->and($resultado['archivos'])->toBe(0);
});
