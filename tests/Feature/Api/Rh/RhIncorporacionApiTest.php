<?php

use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\MobileDevice;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh puede listar incorporaciones', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    Colaborador::factory()->count(2)->create(['estatus' => 'en_incorporacion']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/incorporaciones')
        ->assertOk();
});

test('el listado de incorporaciones pagina en la base con el mismo meta, también filtrando por estado', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    Colaborador::factory()->count(3)->create(['estatus' => 'en_incorporacion']);
    $token = ['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken];

    $pagina = $this->withHeaders($token)->getJson('/api/v1/rh/incorporaciones?per_page=2&page=2')->assertOk();
    $estado = $pagina->json('data.0.estado');
    $filtrada = $this->withHeaders($token)->getJson('/api/v1/rh/incorporaciones?per_page=2&estado='.$estado)->assertOk();

    expect($pagina->json('meta'))->toBe(['current_page' => 2, 'per_page' => 2, 'total' => 3])
        ->and($pagina->json('data'))->toHaveCount(1)
        ->and($pagina->json('data.0'))->toHaveKeys(['id', 'resource_id', 'estado', 'colaborador', 'creado_en', 'acciones_permitidas'])
        ->and($filtrada->json('meta.per_page'))->toBe(2)
        ->and(collect($filtrada->json('data'))->pluck('estado')->unique()->all())->toBe([$estado]);
});

test('rh puede ver el detalle de una incorporacion', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['estatus' => 'en_incorporacion'])]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson("/api/v1/rh/incorporaciones/{$colaborador->colaborador_id}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['colaborador', 'estado', 'progreso', 'documentos', 'acciones_permitidas', 'workflow']]);
});

test('rh solo puede aprobar la incorporacion si todos los documentos obligatorios estan aprobados', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['estatus' => 'en_incorporacion'])]);
    $tipo = DocumentType::factory()->create(['requerido' => true, 'activo' => true]);
    EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id, 'status' => 'en_revision']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/incorporaciones/{$colaborador->colaborador_id}/aprobar")
        ->assertUnprocessable();
});

test('rh aprueba la incorporacion y el colaborador pasa a activo', function () {
    Http::fake();
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['estatus' => 'en_incorporacion'])]);
    MobileDevice::factory()->for($colaborador, 'usuario')->create();
    $tipo = DocumentType::factory()->create(['requerido' => true, 'activo' => true]);
    EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id, 'status' => 'aprobado']);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/incorporaciones/{$colaborador->colaborador_id}/aprobar")
        ->assertOk();

    expect($colaborador->fresh()->colaborador->estatus->value)->toBe('activo')
        ->and($colaborador->fresh()->notifications()->count())->toBe(1);
});

test('rh rechaza la incorporacion con motivo', function () {
    Http::fake();
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['estatus' => 'en_incorporacion'])]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/incorporaciones/{$colaborador->colaborador_id}/rechazar", ['motivo' => 'Documentos ilegibles'])
        ->assertOk();

    expect($colaborador->fresh()->colaborador->incorporacion_decision)->toBe('rechazado');
});
