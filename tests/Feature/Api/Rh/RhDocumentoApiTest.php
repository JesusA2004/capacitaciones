<?php

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\MobileDevice;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh puede listar documentos', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    EmployeeDocument::factory()->count(2)->create(['status' => 'en_revision']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/documentos')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('rh puede ver el detalle de un documento sin exponer la ruta fisica', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $documento = EmployeeDocument::factory()->create(['status' => 'en_revision', 'path' => 'expedientes/999/secreto.pdf']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson("/api/v1/rh/documentos/{$documento->id}")
        ->assertOk();

    expect($respuesta->getContent())->not->toContain('expedientes/999/secreto.pdf')
        ->and($respuesta->getContent())->not->toContain('/mnt/people-storage');
});

test('rh puede ver el archivo en streaming', function () {
    Storage::fake('nas');
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $documento = EmployeeDocument::factory()->create(['status' => 'en_revision', 'path' => 'expedientes/1/archivo.pdf']);
    Storage::disk('nas')->put($documento->path, 'contenido-de-prueba');

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->get("/api/v1/rh/documentos/{$documento->id}/ver")
        ->assertOk();
});

test('rh puede aprobar un documento', function () {
    Http::fake();
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    MobileDevice::factory()->for($colaborador, 'usuario')->create();
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => 'en_revision']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/documentos/{$documento->id}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobado');
});

test('rechazar un documento sin motivo falla', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $documento = EmployeeDocument::factory()->create(['status' => 'en_revision']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/documentos/{$documento->id}/rechazar", [])
        ->assertUnprocessable();
});

test('rechazar un documento con motivo lo marca rechazado y notifica al colaborador', function () {
    Http::fake();
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => 'en_revision']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/documentos/{$documento->id}/rechazar", ['motivo' => 'Ilegible'])
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazado');

    expect($colaborador->fresh()->notifications()->count())->toBe(1);
});

test('subir un documento de incorporacion notifica y encola push para rh', function () {
    Http::fake();

    $colaborador = User::factory()->create(['estatus' => 'en_incorporacion']);
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['requerido' => true, 'activo' => true]);
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    MobileDevice::factory()->for($rh, 'usuario')->create();

    $archivo = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->postJson("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", ['archivo' => $archivo])
        ->assertOk();

    expect($rh->fresh()->notifications()->count())->toBe(1);
});
