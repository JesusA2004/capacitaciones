<?php

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
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

/**
 * `auth()->forgetGuards()` es necesario porque varios tests de este archivo
 * alternan de actor (colaborador/RH) con multiples requests en un mismo
 * test: sin esto, el guard "sanctum" cachea el usuario resuelto en la
 * primera request y las siguientes (con otro Bearer token) siguen viendo
 * al mismo usuario — no es un bug de la API, es cache de AuthManager entre
 * requests simulados dentro de un mismo test.
 */
function headersIncorporacion(User $usuario): array
{
    auth()->forgetGuards();

    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

// --- Colaborador ---------------------------------------------------------

test('un usuario sin token no puede acceder a la incorporacion', function () {
    $this->getJson('/api/v1/colaborador/incorporacion')->assertUnauthorized();
});

test('un colaborador autenticado ve solo su propia incorporacion', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    DocumentType::factory()->count(3)->create(['requerido' => true]);

    $respuesta = $this->withHeaders(headersIncorporacion($colaborador))
        ->getJson('/api/v1/colaborador/incorporacion')
        ->assertOk()
        ->assertJsonStructure(['estado', 'puede_acceder_portal', 'puede_subir_documentos', 'puede_solicitar_cambios', 'progreso', 'documentos']);

    expect($respuesta->json('progreso.total'))->toBe(3);
    expect($respuesta->json('documentos'))->toHaveCount(3);
    expect($respuesta->json('estado'))->toBe('incompleto');

    // El alias /resumen debe devolver exactamente lo mismo.
    $this->withHeaders(headersIncorporacion($colaborador))
        ->getJson('/api/v1/colaborador/incorporacion/resumen')
        ->assertOk()
        ->assertJsonPath('progreso.total', 3);
});

test('el colaborador no ve su expediente completo, solo el estado de sus documentos requeridos', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['requerido' => true]);
    EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'status' => EstadoDocumento::EnRevision->value,
        'comments' => 'Nota interna de RH que el colaborador no debe ver.',
    ]);

    $respuesta = $this->withHeaders(headersIncorporacion($colaborador))
        ->getJson('/api/v1/colaborador/incorporacion')
        ->assertOk();

    // Nunca se expone el bloque de datos personales/laborales del colaborador.
    expect($respuesta->json())->not->toHaveKey('colaborador');

    $documento = collect($respuesta->json('documentos'))->firstWhere('id', $tipo->id);

    expect($documento)->toHaveKeys([
        'id', 'tipo', 'nombre', 'obligatorio', 'estado', 'mensaje', 'motivo_rechazo',
        'puede_subir', 'puede_reemplazar', 'puede_solicitar_cambio', 'fecha_subida', 'fecha_revision',
    ]);
    // Detalle exclusivo de RH que no debe llegar al colaborador.
    expect($documento)->not->toHaveKey('comentarios');
    expect($documento)->not->toHaveKey('subido_por');
    expect($documento)->not->toHaveKey('revisado_por');
    expect($documento)->not->toHaveKey('documento_id');
});

test('el colaborador puede subir un documento pendiente', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['requerido' => true]);

    $this->withHeaders(headersIncorporacion($colaborador))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine.pdf', 200, 'application/pdf'),
        ])
        ->assertOk();

    $documento = EmployeeDocument::query()->where('user_id', $colaborador->id)->where('document_type_id', $tipo->id)->first();

    expect($documento)->not->toBeNull();
    expect($documento->status)->toBe(EstadoDocumento::EnRevision);
});

test('el colaborador no puede reemplazar un documento aprobado sin autorizacion de rh', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['requerido' => true]);
    EmployeeDocument::factory()->aprobado()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id]);

    $this->withHeaders(headersIncorporacion($colaborador))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine-v2.pdf', 200, 'application/pdf'),
        ])
        ->assertStatus(422);
});

test('el colaborador puede solicitar cambio de un documento ya aprobado', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['requerido' => true]);
    $documento = EmployeeDocument::factory()->aprobado()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id]);

    $this->withHeaders(headersIncorporacion($colaborador))
        ->postJson("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/solicitar-cambio")
        ->assertOk()
        ->assertJson(['message' => 'Solicitud de cambio enviada a RH']);

    expect($documento->fresh()->status)->toBe(EstadoDocumento::CambioSolicitado);
});

// --- RH --------------------------------------------------------------------

test('rh con permiso puede listar expedientes', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(headersIncorporacion($rh))
        ->getJson('/api/v1/rh/expedientes')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

test('rh con permiso puede ver el detalle de un expediente', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    DocumentType::factory()->create(['requerido' => true]);

    $this->withHeaders(headersIncorporacion($rh))
        ->getJson("/api/v1/rh/expedientes/{$colaborador->id}")
        ->assertOk()
        ->assertJsonStructure(['colaborador', 'estado_incorporacion', 'documentos']);
});

test('rh puede aprobar y rechazar documentos del expediente', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $tipoAprobar = DocumentType::factory()->create(['requerido' => true]);
    $documentoAprobar = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id, 'document_type_id' => $tipoAprobar->id, 'status' => EstadoDocumento::EnRevision->value,
    ]);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/documentos/{$documentoAprobar->id}/aprobar", ['comentario' => 'Todo en orden.'])
        ->assertOk();
    expect($documentoAprobar->fresh()->status)->toBe(EstadoDocumento::Aprobado);

    $tipoRechazar = DocumentType::factory()->create(['requerido' => true]);
    $documentoRechazar = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id, 'document_type_id' => $tipoRechazar->id, 'status' => EstadoDocumento::EnRevision->value,
    ]);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/documentos/{$documentoRechazar->id}/rechazar", [])
        ->assertStatus(422);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/documentos/{$documentoRechazar->id}/rechazar", ['motivo' => 'Foto ilegible.'])
        ->assertOk();
    expect($documentoRechazar->fresh()->status)->toBe(EstadoDocumento::Rechazado);
});

test('rh puede autorizar el cambio de un documento que el colaborador solicito', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $tipo = DocumentType::factory()->create(['requerido' => true]);
    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id, 'document_type_id' => $tipo->id, 'status' => EstadoDocumento::CambioSolicitado->value,
    ]);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/documentos/{$documento->id}/autorizar-cambio")
        ->assertOk();

    expect($documento->fresh()->status)->toBe(EstadoDocumento::CambioAutorizado);

    // Con el cambio autorizado, el colaborador ya puede subir la nueva version.
    $this->withHeaders(headersIncorporacion($colaborador))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine-v2.pdf', 200, 'application/pdf'),
        ])
        ->assertOk();
});

test('rh solo puede aprobar la incorporacion si todos los documentos obligatorios estan aprobados', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['estatus' => EstadoUsuario::EnIncorporacion->value]);
    $colaborador->assignRole('colaborador');
    $tipo1 = DocumentType::factory()->create(['requerido' => true]);
    $tipo2 = DocumentType::factory()->create(['requerido' => true]);
    EmployeeDocument::factory()->aprobado()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo1->id]);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/aprobar-incorporacion")
        ->assertStatus(422);
    expect($colaborador->fresh()->estatus)->toBe(EstadoUsuario::EnIncorporacion);

    EmployeeDocument::factory()->aprobado()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo2->id]);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/aprobar-incorporacion")
        ->assertOk();

    expect($colaborador->fresh()->estatus)->toBe(EstadoUsuario::Activo);
});

test('rh puede rechazar la incorporacion con motivo, sin activar al colaborador', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['estatus' => EstadoUsuario::EnIncorporacion->value]);
    $colaborador->assignRole('colaborador');

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/rechazar-incorporacion", [])
        ->assertStatus(422);

    $this->withHeaders(headersIncorporacion($rh))
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/rechazar-incorporacion", ['motivo' => 'Documentos incompletos.'])
        ->assertOk();

    expect($colaborador->fresh()->estatus)->toBe(EstadoUsuario::EnIncorporacion);
    expect($colaborador->fresh()->incorporacion_motivo_rechazo)->toBe('Documentos incompletos.');
});

test('un usuario sin permiso de rh recibe 403 al usar los endpoints de expedientes', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $otro = User::factory()->create();
    $otro->assignRole('colaborador');

    $this->withHeaders(headersIncorporacion($colaborador))
        ->getJson('/api/v1/rh/expedientes')
        ->assertForbidden();

    $this->withHeaders(headersIncorporacion($colaborador))
        ->getJson("/api/v1/rh/expedientes/{$otro->id}")
        ->assertForbidden();
});
