<?php

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

test('un colaborador puede subir un documento a su propio expediente', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create();

    $this->actingAs($colaborador)
        ->post(route('rh.expedientes.documentos.store', $colaborador), [
            'document_type_id' => $tipo->id,
            'archivo' => UploadedFile::fake()->create('curp.pdf', 200, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    expect(EmployeeDocument::where('user_id', $colaborador->id)->where('document_type_id', $tipo->id)->exists())->toBeTrue();
});

test('un colaborador no puede subir documentos al expediente de otro colaborador', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $otro = User::factory()->create();
    $tipo = DocumentType::factory()->create();

    $this->actingAs($colaborador)
        ->post(route('rh.expedientes.documentos.store', $otro), [
            'document_type_id' => $tipo->id,
            'archivo' => UploadedFile::fake()->create('curp.pdf', 200, 'application/pdf'),
        ])
        ->assertForbidden();
});

test('subir una nueva version archiva la version anterior', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create();

    $this->actingAs($colaborador)->post(route('rh.expedientes.documentos.store', $colaborador), [
        'document_type_id' => $tipo->id,
        'archivo' => UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf'),
    ]);
    $primero = EmployeeDocument::where('document_type_id', $tipo->id)->firstOrFail();

    // RH pide correccion para poder resubir.
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $this->actingAs($rh)->post(route('rh.documentos.solicitar-correccion', $primero), ['comments' => 'Falta la foto.']);

    $this->actingAs($colaborador)->post(route('rh.expedientes.documentos.store', $colaborador), [
        'document_type_id' => $tipo->id,
        'archivo' => UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf'),
    ]);

    expect($primero->fresh()->status)->toBe(EstadoDocumento::Archivado);

    $segundo = EmployeeDocument::where('document_type_id', $tipo->id)->where('version', 2)->first();
    expect($segundo)->not->toBeNull();
    expect($segundo->previous_version_id)->toBe($primero->id);
});

test('rh_admin puede aprobar un documento pero el propio colaborador no', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => EstadoDocumento::EnRevision->value]);

    $this->actingAs($colaborador)->post(route('rh.documentos.aprobar', $documento))->assertForbidden();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $this->actingAs($rh)->post(route('rh.documentos.aprobar', $documento))->assertSessionHasNoErrors();

    expect($documento->fresh()->status)->toBe(EstadoDocumento::Aprobado);
});

test('rechazar un documento exige un motivo', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $documento = EmployeeDocument::factory()->create(['status' => EstadoDocumento::EnRevision->value]);

    $this->actingAs($rh)
        ->post(route('rh.documentos.rechazar', $documento), [])
        ->assertSessionHasErrors('rejection_reason');
});

test('solo quien tiene permiso de descarga puede descargar el archivo', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id]);

    $otroColaborador = User::factory()->create();
    $otroColaborador->assignRole('colaborador');

    Storage::disk('nas')->put($documento->path, 'contenido-de-prueba');

    $this->actingAs($colaborador)->get(route('rh.documentos.descargar', $documento))->assertOk();
    $this->actingAs($otroColaborador)->get(route('rh.documentos.descargar', $documento))->assertForbidden();
});
