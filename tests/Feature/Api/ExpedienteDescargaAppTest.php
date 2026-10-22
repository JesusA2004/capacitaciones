<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| App móvil (RH): descargar uno por uno los documentos del expediente, el
| expediente completo en ZIP (mismo ExpedienteZipService que la web) y pedir
| corrección. Mismas Policies que la web: un gerente (sin documentos.descargar)
| nunca descarga — 403 en el backend, no solo un botón oculto.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
    Notification::fake();

    $estructura = clEstructura();
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'numero_empleado' => '7788',
        'name' => 'Ana',
        'apellidos' => 'López Ruiz',
    ]);
    $tipo = DocumentType::factory()->create(['nombre' => 'INE app', 'clave' => 'app_ine', 'requerido' => true, 'activo' => true, 'categoria' => CategoriaDocumento::Personales->value]);
    $this->documento = EmployeeDocument::factory()->create([
        'user_id' => null,
        'colaborador_id' => $this->persona->id,
        'document_type_id' => $tipo->id,
        'version' => 1,
        'status' => EstadoDocumento::EnRevision->value,
        'path' => 'expedientes/app/ine.pdf',
        'original_name' => 'INE Ana.pdf',
        'mime' => 'application/pdf',
    ]);
    Storage::disk(config('expedientes.disk'))->put('expedientes/app/ine.pdf', '%PDF-1.4 prueba');

    $this->rh = clUsuario('rh_admin');
});

test('RH descarga el expediente completo en ZIP desde la app y ve los permisos de descarga', function () {
    Sanctum::actingAs($this->rh);

    $this->getJson("/api/v1/rh/expedientes/{$this->persona->id}")
        ->assertOk()
        ->assertJsonPath('puede_descargar_expediente', true)
        ->assertJsonFragment(['id' => $this->documento->id, 'puede_descargar' => true, 'puede_pedir_correccion' => true]);

    $respuesta = $this->get("/api/v1/rh/expedientes/{$this->persona->id}/descargar-zip")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertDownload('Expediente 7788 - Ana Lopez Ruiz.zip');

    $zip = new ZipArchive;
    expect($zip->open($respuesta->baseResponse->getFile()->getPathname()))->toBeTrue()
        ->and($zip->locateName('7788 - Ana López Ruiz/INDICE.csv'))->not->toBeFalse();
    $zip->close();
});

test('RH descarga un documento del expediente como adjunto', function () {
    Sanctum::actingAs($this->rh);

    $respuesta = $this->get("/api/v1/rh/expedientes/{$this->persona->id}/documentos/{$this->documento->id}/descargar")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($respuesta->streamedContent())->toStartWith('%PDF-');

    $this->get("/api/v1/rh/documentos/{$this->documento->id}/descargar")->assertOk();
});

test('un gerente sin permiso de descarga recibe 403 en el ZIP y en cada documento', function () {
    $gerente = clUsuario('gerente', ['sucursal_principal_id' => $this->persona->sucursal_principal_id]);
    Sanctum::actingAs($gerente);

    $this->get("/api/v1/rh/expedientes/{$this->persona->id}/descargar-zip")->assertForbidden();
    $this->get("/api/v1/rh/expedientes/{$this->persona->id}/documentos/{$this->documento->id}/descargar")->assertForbidden();
    expect($this->get("/api/v1/rh/documentos/{$this->documento->id}/descargar")->status())->toBeIn([403, 404]);
});

test('el propio colaborador y una cuenta ajena no descargan el ZIP', function () {
    Sanctum::actingAs(clUsuario('colaborador'));
    $this->get("/api/v1/rh/expedientes/{$this->persona->id}/descargar-zip")->assertForbidden();

    $propio = clUsuario('rh_admin');
    $propio->update(['colaborador_id' => $this->persona->id]);
    Sanctum::actingAs($propio->fresh());
    $this->get("/api/v1/rh/expedientes/{$this->persona->id}/descargar-zip")->assertForbidden();
});

test('sin token el ZIP responde 401', function () {
    $this->getJson("/api/v1/rh/expedientes/{$this->persona->id}/descargar-zip")->assertUnauthorized();
});

test('RH pide corrección de un documento desde la app', function () {
    Sanctum::actingAs($this->rh);

    $this->postJson("/api/v1/rh/expedientes/{$this->persona->id}/documentos/{$this->documento->id}/correccion", [])
        ->assertUnprocessable();

    $this->postJson("/api/v1/rh/expedientes/{$this->persona->id}/documentos/{$this->documento->id}/correccion", ['motivo' => 'La foto está borrosa.'])
        ->assertOk();

    expect($this->documento->fresh()->status)->toBe(EstadoDocumento::RequiereCorreccion);
});
