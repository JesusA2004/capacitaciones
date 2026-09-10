<?php

use App\Enums\EstadoExtraccion;
use App\Jobs\ProcesarDocumentoPersonalJob;
use App\Models\DocumentExtraction;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Documentos\DocumentExtractionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function crearPdfDePruebaExtraccion(string $texto): string
{
    return Pdf::loadHTML('<p>'.$texto.'</p>')->output();
}

test('subir un documento de un tipo elegible encola la extraccion sin bloquear la subida', function () {
    Queue::fake();

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['clave' => 'curp']);

    $this->actingAs($colaborador)
        ->post(route('rh.expedientes.documentos.store', $colaborador), [
            'document_type_id' => $tipo->id,
            'archivo' => UploadedFile::fake()->create('curp.pdf', 50, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(ProcesarDocumentoPersonalJob::class);
});

test('subir un documento de un tipo no elegible no encola nada', function () {
    Queue::fake();

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['clave' => 'contrato']);

    $this->actingAs($colaborador)
        ->post(route('rh.expedientes.documentos.store', $colaborador), [
            'document_type_id' => $tipo->id,
            'archivo' => UploadedFile::fake()->create('contrato.pdf', 50, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    Queue::assertNotPushed(ProcesarDocumentoPersonalJob::class);
});

test('procesar detecta curp y rfc de un pdf real y calcula diferencias contra el colaborador', function () {
    $colaborador = User::factory()->create(['curp' => null, 'rfc' => 'DISTINTO000000XX0']);
    $tipo = DocumentType::factory()->create(['clave' => 'curp']);

    $ruta = 'expedientes/'.$colaborador->id.'/curp.pdf';
    Storage::disk('nas')->put($ruta, crearPdfDePruebaExtraccion('CURP: ABCD123456HDFRRN09 RFC: ABCD123456AB1'));

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'path' => $ruta,
        'extension' => 'pdf',
    ]);

    $extraccion = app(DocumentExtractionService::class)->procesar($documento);

    expect($extraccion->status)->toBe(EstadoExtraccion::Procesado)
        ->and($extraccion->extracted_data['curp'])->toBe('ABCD123456HDFRRN09')
        ->and($extraccion->extracted_data['rfc'])->toBe('ABCD123456AB1')
        ->and($extraccion->differences['curp']['actual'])->toBeNull()
        ->and($extraccion->differences['rfc']['actual'])->toBe('DISTINTO000000XX0')
        ->and($extraccion->differences['rfc']['coincide'])->toBeFalse();
});

test('procesar un documento que no es pdf queda pendiente de revision manual sin tronar', function () {
    $colaborador = User::factory()->create();
    $tipo = DocumentType::factory()->create(['clave' => 'ine']);

    $ruta = 'expedientes/'.$colaborador->id.'/ine.jpg';
    Storage::disk('nas')->put($ruta, 'contenido-binario-de-una-foto-falsa');

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'path' => $ruta,
        'extension' => 'jpg',
    ]);

    $extraccion = app(DocumentExtractionService::class)->procesar($documento);

    expect($extraccion->status)->toBe(EstadoExtraccion::Fallido)
        ->and($extraccion->error_message)->not->toBeNull();
});

test('rh_admin puede ver, aplicar y las sugerencias actualizan al colaborador', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = User::factory()->create(['curp' => null]);
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id]);
    $extraccion = DocumentExtraction::factory()->create([
        'employee_document_id' => $documento->id,
        'user_id' => $colaborador->id,
        'status' => EstadoExtraccion::Procesado->value,
        'extracted_data' => ['curp' => 'NUEV123456HDFRRN01'],
        'differences' => ['curp' => ['detectado' => 'NUEV123456HDFRRN01', 'actual' => null, 'coincide' => false]],
    ]);

    $this->actingAs($usuario)
        ->getJson(route('rh.documentos.extraccion.show', $documento))
        ->assertOk()
        ->assertJsonPath('extraccion.id', $extraccion->id);

    $this->actingAs($usuario)
        ->post(route('rh.documentos.extraccion.aplicar', $documento), [
            'valores' => ['curp' => 'NUEV123456HDFRRN01'],
        ])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->curp)->toBe('NUEV123456HDFRRN01')
        ->and($extraccion->fresh()->status)->toBe(EstadoExtraccion::Revisado);
});

test('rh_admin puede ignorar una extraccion sin cambiar los datos del colaborador', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = User::factory()->create(['curp' => 'ORIGINAL000000XXX00']);
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id]);
    DocumentExtraction::factory()->procesada()->create([
        'employee_document_id' => $documento->id,
        'user_id' => $colaborador->id,
    ]);

    $this->actingAs($usuario)
        ->post(route('rh.documentos.extraccion.ignorar', $documento))
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->curp)->toBe('ORIGINAL000000XXX00');
});

test('un colaborador sin permiso no puede ver la extraccion de otro', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $documento = EmployeeDocument::factory()->create();

    $this->actingAs($usuario)
        ->getJson(route('rh.documentos.extraccion.show', $documento))
        ->assertForbidden();
});

test('la api movil de rh expone la extraccion y respeta el alcance organizacional', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $documento = EmployeeDocument::factory()->create();
    $extraccion = DocumentExtraction::factory()->procesada()->create([
        'employee_document_id' => $documento->id,
        'user_id' => $documento->user_id,
    ]);

    $this->actingAs($usuario, 'sanctum')
        ->getJson(route('api.v1.rh.documentos.extraccion', $documento))
        ->assertOk()
        ->assertJsonPath('data.extraccion.id', $extraccion->id);
});
