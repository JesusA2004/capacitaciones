<?php

use App\Enums\EstadoDocumentoGenerado;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

function crearDocxDePruebaSolicitud(string $texto): string
{
    $phpWord = new PhpWord;
    $phpWord->addSection()->addText($texto);

    $ruta = sys_get_temp_dir().'/'.uniqid('plantilla_solicitud', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    $contenido = file_get_contents($ruta);
    unlink($ruta);

    return $contenido !== false ? $contenido : '';
}

test('generar formato desde una solicitud interna precarga folio y motivo, y queda asociado', function () {
    $colaborador = User::factory()->create(['name' => 'Luis', 'apellidos' => 'Gómez']);
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'folio' => 'SOL-000123',
        'motivo' => 'Cita médica',
        'tipo' => 'permiso_con_goce',
    ]);

    $ruta = 'plantillas/permiso.docx';
    Storage::disk('nas')->put($ruta, crearDocxDePruebaSolicitud('Folio: {{folio_solicitud}} — {{nombre_completo}} — {{motivo_permiso}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta, 'tipo' => 'formato_permiso']);

    $this->actingAs($this->rh)
        ->post(route('rh.formatos.store'), [
            'document_template_id' => $plantilla->id,
            'solicitud_id' => $solicitud->id,
        ])
        ->assertSessionHasNoErrors();

    $documento = GeneratedDocument::where('solicitud_id', $solicitud->id)->firstOrFail();

    expect($documento->user_id)->toBe($colaborador->id)
        ->and($documento->status)->toBe(EstadoDocumentoGenerado::Generado);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('nas')->path($documento->path));
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    expect($xml)->toContain('SOL-000123')
        ->and($xml)->toContain('Luis')
        ->and($xml)->toContain('Cita médica');
});

test('generar formato desde una solicitud de vacaciones queda asociado a esa solicitud', function () {
    $colaborador = User::factory()->create();
    $solicitud = SolicitudVacaciones::factory()->create([
        'user_id' => $colaborador->id,
        'dias_solicitados' => 5,
    ]);

    $ruta = 'plantillas/vacaciones.docx';
    Storage::disk('nas')->put($ruta, crearDocxDePruebaSolicitud('Días: {{dias_vacaciones}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta, 'tipo' => 'formato_vacaciones']);

    $this->actingAs($this->rh)
        ->post(route('rh.formatos.store'), [
            'document_template_id' => $plantilla->id,
            'solicitud_vacaciones_id' => $solicitud->id,
        ])
        ->assertSessionHasNoErrors();

    $documento = GeneratedDocument::where('solicitud_vacaciones_id', $solicitud->id)->firstOrFail();

    expect($documento->user_id)->toBe($colaborador->id);
});

test('no se puede enviar solicitud_id y solicitud_vacaciones_id a la vez', function () {
    $solicitud = SolicitudInterna::factory()->create();
    $solicitudVacaciones = SolicitudVacaciones::factory()->create();
    $plantilla = DocumentTemplate::factory()->create();

    $this->actingAs($this->rh)
        ->post(route('rh.formatos.store'), [
            'document_template_id' => $plantilla->id,
            'solicitud_id' => $solicitud->id,
            'solicitud_vacaciones_id' => $solicitudVacaciones->id,
        ])
        ->assertSessionHasErrors('solicitud_id');
});

test('rh_admin puede subir el documento firmado y queda archivado en el expediente del colaborador', function () {
    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);
    $tipoDocumento = DocumentType::factory()->create();

    $documento = GeneratedDocument::factory()->create([
        'user_id' => $colaborador->id,
        'solicitud_id' => $solicitud->id,
        'status' => EstadoDocumentoGenerado::Entregado,
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.formatos.subir-firmado', $documento), [
            'document_type_id' => $tipoDocumento->id,
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $documento->refresh();

    expect($documento->status)->toBe(EstadoDocumentoGenerado::Firmado)
        ->and($documento->signed_document_id)->not->toBeNull();

    $firmado = EmployeeDocument::findOrFail($documento->signed_document_id);
    expect($firmado->user_id)->toBe($colaborador->id)
        ->and($firmado->document_type_id)->toBe($tipoDocumento->id);
});

test('un colaborador sin permiso no puede subir un documento firmado', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $documento = GeneratedDocument::factory()->create(['user_id' => $colaborador->id]);
    $tipoDocumento = DocumentType::factory()->create();

    $this->actingAs($colaborador)
        ->post(route('rh.formatos.subir-firmado', $documento), [
            'document_type_id' => $tipoDocumento->id,
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});
