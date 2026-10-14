<?php

use App\Models\Colaborador;
use App\Models\DocumentExtraction;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('nas');
});

function crearDocumentoElegibleParaComando(string $texto, array $colaboradorState = []): EmployeeDocument
{
    $colaborador = User::factory()->for(Colaborador::factory()->state($colaboradorState), 'colaborador')->create();
    $tipo = DocumentType::query()->firstOrCreate(['clave' => 'curp'], DocumentType::factory()->make(['clave' => 'curp'])->toArray());
    $ruta = 'expedientes/'.$colaborador->id.'/curp-'.$colaborador->id.'.pdf';
    Storage::disk('nas')->put($ruta, Pdf::loadHTML('<p>'.$texto.'</p>')->output());

    return EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'colaborador_id' => $colaborador->colaborador->id,
        'document_type_id' => $tipo->id,
        'path' => $ruta,
        'extension' => 'pdf',
    ]);
}

test('sin --ejecutar es un reporte: no lee el NAS ni crea extracciones', function () {
    crearDocumentoElegibleParaComando('CURP: ABCD123456HDFRRN09', ['curp' => null]);

    $this->artisan('expedientes:extraer-existentes')->assertSuccessful();

    expect(DocumentExtraction::query()->count())->toBe(0);
});

test('con --ejecutar procesa los documentos elegibles pendientes y crea la sugerencia', function () {
    $documento = crearDocumentoElegibleParaComando('CURP: ABCD123456HDFRRN09', ['curp' => null]);

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true])->assertSuccessful();

    $extraccion = DocumentExtraction::query()->where('employee_document_id', $documento->id)->first();
    expect($extraccion)->not->toBeNull()
        ->and($extraccion->extracted_data['curp'] ?? null)->toBe('ABCD123456HDFRRN09');
});

test('una segunda corrida con --ejecutar no reprocesa un documento ya vigente', function () {
    crearDocumentoElegibleParaComando('CURP: ABCD123456HDFRRN09', ['curp' => null]);

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true])->assertSuccessful();
    $primeraActualizacion = DocumentExtraction::query()->first()->updated_at;

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true])->assertSuccessful();

    expect(DocumentExtraction::query()->count())->toBe(1)
        ->and(DocumentExtraction::query()->first()->updated_at)->toEqual($primeraActualizacion);
});

test('--forzar reprocesa aunque ya exista una extraccion vigente', function () {
    crearDocumentoElegibleParaComando('CURP: ABCD123456HDFRRN09', ['curp' => null]);

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true])->assertSuccessful();
    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true, '--forzar' => true])->assertSuccessful();

    expect(DocumentExtraction::query()->count())->toBe(1);
});

test('--solo-faltantes excluye colaboradores que ya tienen el dato capturado', function () {
    // Todas las columnas de DocumentExtractionService::columnasColaborador()
    // capturadas: con --solo-faltantes no debe quedar nada por completar.
    crearDocumentoElegibleParaComando('CURP: ABCD123456HDFRRN09', [
        'curp' => 'YA1234567HDFRRN08', 'rfc' => 'YA123456AB1', 'nss' => '12345678901', 'fecha_nacimiento' => '1990-01-01',
        'domicilio_cp' => '12345', 'genero' => 'masculino', 'clave_elector' => 'ABCD123456789012AB',
        'correo_personal' => 'persona@example.com', 'telefono' => '5512345678', 'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero', 'lugar_nacimiento' => 'CDMX', 'profesion' => 'Contador',
        'domicilio' => 'Calle 1 #23', 'domicilio_colonia' => 'Centro', 'domicilio_municipio' => 'Cuauhtémoc',
        'domicilio_estado' => 'CDMX', 'beneficiario_nombre' => 'Juan Pérez', 'beneficiario_parentesco' => 'Hermano',
        'contacto_emergencia_nombre' => 'Ana Pérez', 'contacto_emergencia_telefono' => '5587654321',
        'contacto_emergencia_parentesco' => 'Madre',
    ]);

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true, '--solo-faltantes' => true])->assertSuccessful();

    expect(DocumentExtraction::query()->count())->toBe(0);
});

test('--colaborador limita el procesamiento a un solo colaborador', function () {
    $documentoA = crearDocumentoElegibleParaComando('CURP: ABCD123456HDFRRN09', ['curp' => null]);
    crearDocumentoElegibleParaComando('CURP: WXYZ987654MDFRRN05', ['curp' => null]);

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true, '--colaborador' => $documentoA->colaborador_id])->assertSuccessful();

    expect(DocumentExtraction::query()->count())->toBe(1)
        ->and(DocumentExtraction::query()->first()->employee_document_id)->toBe($documentoA->id);
});
