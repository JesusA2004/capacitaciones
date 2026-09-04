<?php

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function crearInvitacion(array $datos = []): array
{
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $servicio = app(IncorporacionInvitacionService::class);

    return [...$servicio->crear($datos, $rh), 'rh' => $rh];
}

test('un token de invitacion valido devuelve datos prellenados y fases', function () {
    ['token' => $token, 'invitacion' => $invitacion] = crearInvitacion([
        'nombre_prellenado' => 'Ana Torres',
        'email' => 'ana@mrlana.test',
    ]);

    $respuesta = $this->getJson("/api/v1/incorporacion/invitaciones/{$token}/validar")
        ->assertOk()
        ->assertJsonPath('valida', true)
        ->assertJsonPath('estado', 'activo')
        ->assertJsonPath('datos_prellenados.nombre', 'Ana Torres')
        ->assertJsonPath('datos_prellenados.email', 'ana@mrlana.test');

    expect($respuesta->json('fases'))->toHaveCount(3);
    expect($invitacion->fresh()->estado->value)->toBe('activo');
});

test('un qr vencido responde con estado controlado, nunca 200', function () {
    ['token' => $token] = crearInvitacion(['expires_at' => now()->subDay()->toDateTimeString()]);

    $this->getJson("/api/v1/incorporacion/invitaciones/{$token}/validar")
        ->assertStatus(410)
        ->assertJsonPath('valida', false)
        ->assertJsonPath('estado', 'vencido');
});

test('un qr revocado responde con estado controlado', function () {
    ['token' => $token, 'invitacion' => $invitacion] = crearInvitacion();
    app(IncorporacionInvitacionService::class)->revocar($invitacion);

    $this->getJson("/api/v1/incorporacion/invitaciones/{$token}/validar")
        ->assertStatus(410)
        ->assertJsonPath('valida', false)
        ->assertJsonPath('estado', 'revocado');
});

test('un token que no existe responde 404 controlado', function () {
    $this->getJson('/api/v1/incorporacion/invitaciones/token-inexistente/validar')
        ->assertStatus(404)
        ->assertJsonPath('valida', false)
        ->assertJsonPath('estado', 'invalido');
});

test('un qr ya usado no permite un segundo registro cuando max_usos es 1', function () {
    ['token' => $token] = crearInvitacion();

    $datos = [
        'name' => 'Colaborador Uno',
        'email' => 'colaborador1@mrlana.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ];

    $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", $datos)->assertCreated();

    $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", [
        ...$datos,
        'email' => 'colaborador2@mrlana.test',
    ])->assertStatus(410)->assertJsonPath('estado', 'usado');
});

test('el registro exige que el correo coincida cuando la invitacion trae uno predefinido', function () {
    ['token' => $token] = crearInvitacion(['email' => 'reservado@mrlana.test']);

    $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", [
        'name' => 'Colaborador',
        'email' => 'otro@mrlana.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertStatus(422)->assertJsonPath('estado', 'correo_no_coincide');
});

test('registrar con un qr valido crea al colaborador en_incorporacion, con rol colaborador y token sanctum', function () {
    ['token' => $token] = crearInvitacion();

    $respuesta = $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", [
        'name' => 'Colaborador Nuevo',
        'apellidos' => 'Pérez',
        'email' => 'nuevo@mrlana.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
        'telefono' => '5512345678',
    ])->assertCreated();

    $respuesta->assertJsonPath('usuario.estatus', 'en_incorporacion');
    $respuesta->assertJsonPath('usuario.roles.0', 'colaborador');
    expect($respuesta->json('token'))->not->toBeEmpty();

    $usuario = User::query()->where('email', 'nuevo@mrlana.test')->firstOrFail();
    expect($usuario->estatus)->toBe(EstadoUsuario::EnIncorporacion);
    expect($usuario->hasRole('colaborador'))->toBeTrue();
});

test('el usuario creado por qr puede usar de inmediato /colaborador/incorporacion y subir documentos permitidos', function () {
    $tipo = DocumentType::factory()->create(['requerido' => true]);
    ['token' => $token] = crearInvitacion();

    $registro = $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", [
        'name' => 'Colaborador App',
        'email' => 'app@mrlana.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated();

    $headers = ['Authorization' => 'Bearer '.$registro->json('token')];

    $this->withHeaders($headers)
        ->getJson('/api/v1/colaborador/incorporacion')
        ->assertOk()
        ->assertJsonPath('estado', 'incompleto');

    $subida = $this->withHeaders($headers)
        ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine.pdf', 200, 'application/pdf'),
        ])
        ->assertOk();

    $documento = EmployeeDocument::query()->where('document_type_id', $tipo->id)->firstOrFail();
    expect($documento->status)->toBe(EstadoDocumento::EnRevision);

    // El archivo quedo en el disco NAS configurado, nunca en un disco publico.
    Storage::disk('nas')->assertExists($documento->path);

    // Nunca se expone la ruta fisica del NAS ni el disco en la respuesta JSON.
    $json = json_encode($subida->json());
    expect($json)->not->toContain('/mnt/people-storage');
    expect($json)->not->toContain('"disk"');
    expect($json)->not->toContain('"path"');
});

test('rh puede aprobar la incorporacion de un colaborador registrado por qr y activarlo', function () {
    $tipo = DocumentType::factory()->create(['requerido' => true]);
    ['token' => $token] = crearInvitacion();

    $registro = $this->postJson("/api/v1/incorporacion/invitaciones/{$token}/registrar", [
        'name' => 'Colaborador Aprobado',
        'email' => 'aprobado@mrlana.test',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated();

    $colaborador = User::query()->where('email', 'aprobado@mrlana.test')->firstOrFail();
    EmployeeDocument::factory()->aprobado()->create(['user_id' => $colaborador->id, 'document_type_id' => $tipo->id]);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('t')->plainTextToken])
        ->postJson("/api/v1/rh/expedientes/{$colaborador->id}/aprobar-incorporacion")
        ->assertOk();

    expect($colaborador->fresh()->estatus)->toBe(EstadoUsuario::Activo);
});
