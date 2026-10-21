<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * «Descargar expediente completo»: ZIP con vigentes por categoría, historial
 * de versiones, documentos generados, foto e INDICE.csv (faltantes «No
 * entregado», archivos ilegibles «No se pudo leer del almacenamiento»).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
});

/**
 * @return array{0: Colaborador, 1: User}
 */
function prepararExpedienteZip(): array
{
    $disco = Storage::disk(config('expedientes.disk'));
    $persona = Colaborador::factory()->create(['numero_empleado' => '4321', 'name' => 'José', 'apellidos' => 'Núñez Pérez', 'foto_path' => 'expedientes/x/foto/Foto de perfil.jpg']);
    $disco->put('expedientes/x/foto/Foto de perfil.jpg', 'foto');

    $ine = DocumentType::factory()->create(['nombre' => 'INE: identificación', 'clave' => 'zip_ine', 'requerido' => true, 'activo' => true, 'categoria' => CategoriaDocumento::Personales->value]);
    DocumentType::factory()->create(['nombre' => 'CURP zip', 'clave' => 'zip_curp', 'requerido' => true, 'activo' => true, 'categoria' => CategoriaDocumento::Personales->value]);
    $comprobante = DocumentType::factory()->create(['nombre' => 'Comprobante zip', 'clave' => 'zip_comprobante', 'requerido' => true, 'activo' => true, 'categoria' => CategoriaDocumento::Personales->value]);

    $v1 = EmployeeDocument::factory()->create(['user_id' => null, 'colaborador_id' => $persona->id, 'document_type_id' => $ine->id, 'version' => 1, 'status' => EstadoDocumento::Archivado->value, 'path' => 'expedientes/x/ine-v1.pdf']);
    EmployeeDocument::factory()->create(['user_id' => null, 'colaborador_id' => $persona->id, 'document_type_id' => $ine->id, 'version' => 2, 'previous_version_id' => $v1->id, 'status' => EstadoDocumento::Aprobado->value, 'path' => 'expedientes/x/ine-v2.pdf']);
    $disco->put('expedientes/x/ine-v1.pdf', 'ine uno');
    $disco->put('expedientes/x/ine-v2.pdf', 'ine dos');

    // Su archivo NO existe en el NAS: debe reportarse, no omitirse.
    EmployeeDocument::factory()->create(['user_id' => null, 'colaborador_id' => $persona->id, 'document_type_id' => $comprobante->id, 'version' => 1, 'status' => EstadoDocumento::EnRevision->value, 'path' => 'expedientes/x/no-existe.pdf']);

    GeneratedDocument::factory()->create([
        'colaborador_id' => $persona->id,
        'path' => 'documentos-generados/contrato.pdf',
        'original_name' => 'contrato.pdf',
        'mime' => 'application/pdf',
        'titulo' => 'Contrato laboral',
        'categoria' => CategoriaDocumento::Contratos->value,
        'estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente->value,
    ]);
    $disco->put('documentos-generados/contrato.pdf', 'contrato');

    $rh = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $rh->assignRole('rh_admin');

    return [$persona, $rh];
}

test('RH descarga el expediente completo en ZIP con vigentes, historial, generados, foto e índice', function () {
    [$persona, $rh] = prepararExpedienteZip();

    $respuesta = $this->actingAs($rh)->get(route('rh.expedientes.descargar-zip', $persona));
    // Nombre ASCII de respaldo + filename* UTF-8 con los acentos.
    $respuesta->assertOk()->assertDownload('Expediente 4321 - Jose Nunez Perez.zip');
    expect($respuesta->headers->get('Content-Disposition'))->toContain(rawurlencode('Expediente 4321 - José Núñez Pérez.zip'));

    $ruta = $respuesta->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($ruta))->toBeTrue();

    $entradas = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entradas[] = $zip->getNameIndex($i);
    }

    $raiz = '4321 - José Núñez Pérez';
    expect($entradas)->toContain("{$raiz}/01 Personales/INE identificación (Aprobado).pdf")
        ->toContain("{$raiz}/Historial de versiones/INE identificación - v1 (Archivado).pdf")
        ->toContain("{$raiz}/Foto de perfil.jpg")
        ->toContain("{$raiz}/INDICE.csv")
        ->and(collect($entradas)->contains(fn (string $e) => str_starts_with($e, "{$raiz}/Documentos generados/Contrato laboral (Firmado físicamente)")))->toBeTrue()
        ->and(collect($entradas)->contains(fn (string $e) => str_contains($e, 'Comprobante zip')))->toBeFalse();

    expect($zip->getFromName("{$raiz}/01 Personales/INE identificación (Aprobado).pdf"))->toBe('ine dos');

    $indice = (string) $zip->getFromName("{$raiz}/INDICE.csv");
    $zip->close();
    @unlink($ruta);

    expect(str_starts_with($indice, "\u{FEFF}"))->toBeTrue()
        ->and($indice)->toContain('CURP zip')
        ->toContain('No entregado')
        ->toContain('Comprobante zip')
        ->toContain('No se pudo leer del almacenamiento')
        ->toContain('Historial de versiones')
        ->toContain('Contrato laboral');

    $filaCurp = collect(explode("\n", $indice))->first(fn (string $l) => str_contains($l, 'CURP zip'));
    expect($filaCurp)->toContain('No entregado');
    $filaComprobante = collect(explode("\n", $indice))->first(fn (string $l) => str_contains($l, 'Comprobante zip'));
    expect($filaComprobante)->toContain('No se pudo leer del almacenamiento');
});

test('quien no puede ver el expediente recibe 403 y el propio colaborador tampoco descarga el ZIP', function () {
    [$persona] = prepararExpedienteZip();

    $otro = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $otro->assignRole('colaborador');
    $this->actingAs($otro)->get(route('rh.expedientes.descargar-zip', $persona))->assertForbidden();

    $propio = User::factory()->create(['colaborador_id' => $persona->id]);
    $propio->assignRole('colaborador');
    $this->actingAs($propio)->get(route('rh.expedientes.descargar-zip', $persona))->assertForbidden();
});

test('el expediente de RH muestra el botón solo a quien puede descargar', function () {
    [$persona, $rh] = prepararExpedienteZip();

    $this->actingAs($rh)->get(route('rh.expedientes.show', $persona))
        ->assertInertia(fn ($page) => $page->where('puedeDescargarExpediente', true));
});
