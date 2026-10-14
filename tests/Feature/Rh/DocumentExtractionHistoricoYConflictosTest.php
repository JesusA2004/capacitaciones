<?php

use App\Enums\EstadoCivil;
use App\Enums\EstadoExtraccion;
use App\Enums\Genero;
use App\Models\Colaborador;
use App\Models\DocumentExtraction;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\Documentos\DocumentExtractionService;
use App\Services\Documentos\RegexPersonalDataExtractor;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function crearPdfHistorico(Colaborador $colaborador, string $texto, string $estado = ExpedienteHistorico::VINCULADO): ExpedienteHistorico
{
    $bytes = Pdf::loadHTML('<p>'.$texto.'</p>')->output();
    $ruta = 'expedientes/historico/'.$colaborador->id.'/historico.pdf';
    Storage::disk('nas')->put($ruta, $bytes);

    return ExpedienteHistorico::query()->create([
        'colaborador_id' => $estado === ExpedienteHistorico::VINCULADO ? $colaborador->id : null,
        'estado' => $estado,
        'disk' => 'nas',
        'path' => $ruta,
        'original_name' => 'historico.pdf',
        'stored_name' => 'historico.pdf',
        'mime' => 'application/pdf',
        'extension' => 'pdf',
        'size' => strlen($bytes),
        'hash' => hash('sha256', $bytes),
        'source_path' => 'origen/historico.pdf',
    ]);
}

test('un ExpedienteHistorico vinculado se procesa y sugiere datos', function () {
    $colaborador = Colaborador::factory()->create(['curp' => null]);
    $historico = crearPdfHistorico($colaborador, 'CURP: ABCD123456HDFRRN09');

    $extraccion = app(DocumentExtractionService::class)->procesarHistorico($historico);

    expect($extraccion->status)->toBe(EstadoExtraccion::Procesado)
        ->and($extraccion->expediente_historico_id)->toBe($historico->id)
        ->and($extraccion->employee_document_id)->toBeNull()
        ->and($extraccion->extracted_data['curp'])->toBe('ABCD123456HDFRRN09');
});

test('un ExpedienteHistorico pendiente de vincular se considera no elegible y no se procesa', function () {
    $colaborador = Colaborador::factory()->create();
    $historico = crearPdfHistorico($colaborador, 'CURP: ABCD123456HDFRRN09', ExpedienteHistorico::PENDIENTE);

    expect(DocumentExtractionService::historicoElegible($historico))->toBeFalse();

    expect(fn () => app(DocumentExtractionService::class)->procesarHistorico($historico))
        ->toThrow(InvalidArgumentException::class);
});

test('el comando expedientes:extraer-existentes tambien recorre historicos vinculados y omite los pendientes', function () {
    $colaborador = Colaborador::factory()->create(['curp' => null]);
    crearPdfHistorico($colaborador, 'CURP: ABCD123456HDFRRN09');
    crearPdfHistorico(Colaborador::factory()->create(), 'CURP: WXYZ987654MDFRRN05', ExpedienteHistorico::PENDIENTE);

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true])->assertSuccessful();

    expect(DocumentExtraction::query()->whereNotNull('expediente_historico_id')->count())->toBe(1);
});

test('idempotencia: mismo hash y misma version del extractor no se vuelve a procesar un historico', function () {
    $colaborador = Colaborador::factory()->create(['curp' => null]);
    $historico = crearPdfHistorico($colaborador, 'CURP: ABCD123456HDFRRN09');
    $extraccion = app(DocumentExtractionService::class)->procesarHistorico($historico);

    expect(app(DocumentExtractionService::class)->tieneExtraccionVigenteHistorico($historico))->toBeTrue();

    $this->artisan('expedientes:extraer-existentes', ['--ejecutar' => true])->assertSuccessful();

    expect(DocumentExtraction::query()->count())->toBe(1)
        ->and(DocumentExtraction::query()->first()->id)->toBe($extraccion->id);
});

test('beneficiario no se traga parentesco ni domicilio en el mismo texto', function () {
    $resultado = (new RegexPersonalDataExtractor)->extraer(
        "BENEFICIARIO: MARÍA DEL CARMEN PÉREZ\nPARENTESCO: MADRE\nDOMICILIO: CALLE FALSA 123"
    );

    expect($resultado['data']['beneficiario_nombre'])->toBe('MARÍA DEL CARMEN PÉREZ')
        ->and($resultado['data']['beneficiario_parentesco'])->toBe('MADRE');

    expect($resultado['data']['beneficiario_nombre'])->not->toContain('PARENTESCO')
        ->and($resultado['data']['beneficiario_nombre'])->not->toContain('DOMICILIO')
        ->and($resultado['data']['beneficiario_parentesco'])->not->toContain('DOMICILIO');
});

test('beneficiario y contacto de emergencia nunca se cruzan', function () {
    $resultado = (new RegexPersonalDataExtractor)->extraer(
        "BENEFICIARIO: JUAN LOPEZ\nPARENTESCO: PADRE\nCONTACTO DE EMERGENCIA: ANA LOPEZ\nTELEFONO: 5512345678\nPARENTESCO: HERMANA"
    );

    expect($resultado['data']['beneficiario_nombre'])->toBe('JUAN LOPEZ')
        ->and($resultado['data']['beneficiario_parentesco'])->toBe('PADRE')
        ->and($resultado['data']['contacto_emergencia_nombre'])->toBe('ANA LOPEZ')
        ->and($resultado['data']['contacto_emergencia_telefono'])->toBe('5512345678')
        ->and($resultado['data']['contacto_emergencia_parentesco'])->toBe('HERMANA');
});

test('conflicto entre dos documentos del mismo colaborador se reporta sin elegir uno solo', function () {
    $colaborador = Colaborador::factory()->create();
    $tipo = DocumentType::factory()->create(['clave' => 'curp']);

    $docA = EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id, 'document_type_id' => $tipo->id]);
    $docB = EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id, 'document_type_id' => $tipo->id]);

    DocumentExtraction::factory()->procesada()->create([
        'employee_document_id' => $docA->id,
        'colaborador_id' => $colaborador->id,
        'extracted_data' => ['curp' => 'AAAA111111HDFRRN01'],
        'differences' => ['curp' => ['detectado' => 'AAAA111111HDFRRN01', 'actual' => null, 'coincide' => false]],
    ]);
    DocumentExtraction::factory()->procesada()->create([
        'employee_document_id' => $docB->id,
        'colaborador_id' => $colaborador->id,
        'extracted_data' => ['curp' => 'BBBB222222HDFRRN02'],
        'differences' => ['curp' => ['detectado' => 'BBBB222222HDFRRN02', 'actual' => null, 'coincide' => false]],
    ]);

    $conflictos = app(DocumentExtractionService::class)->conflictos($colaborador);

    expect($conflictos)->toHaveKey('curp')
        ->and($conflictos['curp'])->toHaveCount(2)
        ->and(collect($conflictos['curp'])->pluck('valor')->all())->toEqualCanonicalizing(['AAAA111111HDFRRN01', 'BBBB222222HDFRRN02']);
});

test('aplicar escribe domicilio, correo personal, telefono y codigo postal en las columnas reales', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['domicilio' => null, 'correo_personal' => null, 'telefono' => null, 'domicilio_cp' => null]);
    $documento = EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id]);
    $extraccion = DocumentExtraction::factory()->create([
        'employee_document_id' => $documento->id,
        'colaborador_id' => $colaborador->id,
        'status' => EstadoExtraccion::Procesado->value,
    ]);

    app(DocumentExtractionService::class)->aplicar($extraccion, [
        'domicilio' => 'CALLE FALSA 123',
        'correo' => 'persona@example.com',
        'telefono' => '5512345678',
        'codigo_postal' => '12345',
    ], $usuario);

    $colaborador->refresh();
    expect($colaborador->domicilio)->toBe('CALLE FALSA 123')
        ->and($colaborador->correo_personal)->toBe('persona@example.com')
        ->and($colaborador->telefono)->toBe('5512345678')
        ->and($colaborador->domicilio_cp)->toBe('12345');
});

test('sexo se aplica a genero solo con mapeo inequivoco H/M', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['genero' => null]);
    $documento = EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id]);
    $extraccion = DocumentExtraction::factory()->create([
        'employee_document_id' => $documento->id,
        'colaborador_id' => $colaborador->id,
        'status' => EstadoExtraccion::Procesado->value,
    ]);

    app(DocumentExtractionService::class)->aplicar($extraccion, ['sexo' => 'H'], $usuario);

    expect($colaborador->fresh()->genero)->toBe(Genero::Masculino);
});

test('estado civil no reconocido no se autoaplica: aplicar() rechaza con mensaje humano y no marca revisado', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['estado_civil' => null]);
    $documento = EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id]);
    $extraccion = DocumentExtraction::factory()->create([
        'employee_document_id' => $documento->id,
        'colaborador_id' => $colaborador->id,
        'status' => EstadoExtraccion::Procesado->value,
    ]);

    expect(fn () => app(DocumentExtractionService::class)->aplicar($extraccion, ['estado_civil' => 'TEXTO RARO NO RECONOCIDO'], $usuario))
        ->toThrow(ValidationException::class);

    expect($colaborador->fresh()->estado_civil)->toBeNull()
        ->and($extraccion->fresh()->status)->toBe(EstadoExtraccion::Procesado);
});

test('estado civil reconocido se aplica al enum real', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['estado_civil' => null]);
    $documento = EmployeeDocument::factory()->create(['colaborador_id' => $colaborador->id]);
    $extraccion = DocumentExtraction::factory()->create([
        'employee_document_id' => $documento->id,
        'colaborador_id' => $colaborador->id,
        'status' => EstadoExtraccion::Procesado->value,
    ]);

    app(DocumentExtractionService::class)->aplicar($extraccion, ['estado_civil' => 'SOLTERO'], $usuario);

    expect($colaborador->fresh()->estado_civil)->toBe(EstadoCivil::Soltero);
});
