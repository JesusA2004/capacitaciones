<?php

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\RegistroAppService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Registro completo en la app antes de entrar (RegistroAppService,
 * docs/REGISTRO_APP.md): quien crea su cuenta por QR captura sus datos
 * contractuales y sube sus documentos requeridos; la API marca
 * `registro_completo` y, a la app que declara X-People-Registro, solo le
 * responde lo que el asistente necesita. Las APKs viejas no cambian.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

/** Datos personales completos y válidos (lo que pide el contrato). */
function raDatosCompletos(): array
{
    return [
        'fecha_nacimiento' => '1995-04-12',
        'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero',
        'lugar_nacimiento' => 'Cuernavaca, Morelos',
        'curp' => 'TOAA950412MMSRRN09',
        'rfc' => 'TOAA950412AB1',
        'nss' => '12345678901',
        'clave_elector' => 'TRANAN95041217M100',
        'profesion' => 'Ventas',
        'correo_personal' => 'ana.personal@mrlana.test',
        'domicilio_calle' => 'Av. Morelos',
        'domicilio_numero_exterior' => '120',
        'domicilio_colonia' => 'Centro',
        'domicilio_cp' => '62000',
        'domicilio_municipio' => 'Cuernavaca',
        'domicilio_estado' => 'Morelos',
        'contacto_emergencia_nombre' => 'Rosa Torres',
        'contacto_emergencia_parentesco' => 'Madre',
        'contacto_emergencia_telefono' => '7771234567',
        'beneficiario_nombre' => 'Rosa Torres',
        'beneficiario_parentesco' => 'Madre',
    ];
}

/**
 * Colaborador en contratación (como lo deja RH) + su cuenta creada por QR.
 * Teléfono y género los trae del alta de RH (factory): quedan bloqueados.
 *
 * @return array{colaborador: Colaborador, headers: array<string, string>, token: string}
 */
function raRegistrarPorQr(): array
{
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = clColaboradorEnContratacion();
    ['token' => $tokenQr] = app(IncorporacionInvitacionService::class)->crear(['colaborador_id' => $colaborador->id], $rh);

    $respuesta = test()->postJson("/api/v1/incorporacion/invitaciones/{$tokenQr}/registrar", [
        'name' => 'Ana',
        'apellidos' => 'Torres',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated()
        ->assertJsonPath('usuario.registro_completo', false);

    $token = (string) $respuesta->json('token');

    return [
        'colaborador' => $colaborador->fresh(),
        'token' => $token,
        'headers' => ['Authorization' => 'Bearer '.$token, RegistroAppService::CABECERA_CLIENTE => '1'],
    ];
}

test('quien se registra por qr no tiene el registro completo y /me lo indica', function () {
    ['headers' => $headers] = raRegistrarPorQr();

    test()->withHeaders($headers)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('registro_completo', false);

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/registro')
        ->assertOk()
        ->assertJsonPath('data.aplica', true)
        ->assertJsonPath('data.registro_completo', false)
        ->assertJsonPath('data.puede_finalizar', false);
});

test('con la app nueva el backend bloquea la entrada hasta completar el registro', function () {
    ['headers' => $headers] = raRegistrarPorQr();

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes')
        ->assertForbidden()
        ->assertJsonPath('codigo', 'registro_incompleto');

    // Lo que el asistente necesita sí responde.
    test()->withHeaders($headers)->getJson('/api/v1/colaborador/incorporacion')->assertOk();
    test()->withHeaders($headers)->getJson('/api/v1/colaborador/datos-faltantes')->assertOk();
    test()->withHeaders($headers)->getJson('/api/v1/mobile/bootstrap')->assertOk();
});

test('una apk anterior (sin la cabecera) sigue funcionando como siempre', function () {
    ['token' => $token] = raRegistrarPorQr();
    $headers = ['Authorization' => 'Bearer '.$token];

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/incorporacion')->assertOk();
    $respuesta = test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');

    expect($respuesta->status())->not->toBe(403);
    expect($respuesta->json('codigo'))->not->toBe('registro_incompleto');
});

test('un colaborador activo nunca queda bloqueado por el registro', function () {
    $colaborador = Colaborador::factory()->create();
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    expect(app(RegistroAppService::class)->aplica($usuario))->toBeFalse();

    test()->actingAs($usuario, 'sanctum')
        ->withHeaders([RegistroAppService::CABECERA_CLIENTE => '1'])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('registro_completo', true);

    test()->actingAs($usuario, 'sanctum')
        ->withHeaders([RegistroAppService::CABECERA_CLIENTE => '1'])
        ->postJson('/api/v1/colaborador/registro/datos', ['datos' => ['curp' => 'TOAA950412MMSRRN09']])
        ->assertUnprocessable();
});

test('guarda los datos del registro, valida formatos y no sobrescribe lo que capturó RH', function () {
    ['headers' => $headers, 'colaborador' => $colaborador] = raRegistrarPorQr();

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', ['datos' => ['curp' => 'NO-ES-CURP']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datos.curp');

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', [
        'datos' => ['curp' => 'toaa950412mmsrrn09', 'rfc' => 'TOAA950412AB1'],
        'datos_medicos' => ['alergias' => 'Penicilina'],
    ])->assertOk();

    $colaborador->refresh();
    expect($colaborador->curp)->toBe('TOAA950412MMSRRN09')
        ->and($colaborador->rfc)->toBe('TOAA950412AB1')
        ->and(ColaboradorDatosMedicos::query()->where('colaborador_id', $colaborador->id)->first()?->alergias)->toBe('Penicilina');

    // Lo capturado por la propia persona en el registro sí se corrige.
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', ['datos' => ['rfc' => 'TOAA950412AB2']])->assertOk();
    expect($colaborador->fresh()->rfc)->toBe('TOAA950412AB2');

    // El teléfono lo registró RH en la contratación: no se toca desde aquí.
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', ['datos' => ['telefono' => '5550000000']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datos');
});

test('no se puede finalizar sin datos ni documentos; completo, entra a la app', function () {
    $tipo = DocumentType::factory()->create(['requerido' => true, 'aplica_alta' => true, 'activo' => true]);
    ['headers' => $headers, 'colaborador' => $colaborador] = raRegistrarPorQr();

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('registro');

    $datos = raDatosCompletos();
    // Solo lo que esté vacío (lo que RH ya capturó no se manda).
    $datos = array_filter($datos, fn (string $campo): bool => blank($colaborador->getAttribute($campo)), ARRAY_FILTER_USE_KEY);
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', ['datos' => $datos])->assertOk();

    // Faltan los documentos: se puede cerrar marcándolos «No tengo este
    // documento» (carta compromiso), pero sin marcarlos el cierre se rechaza.
    test()->withHeaders($headers)->getJson('/api/v1/colaborador/registro')
        ->assertOk()
        ->assertJsonPath('data.datos.completo', true)
        ->assertJsonPath('data.documentos.completo', false)
        ->assertJsonPath('data.puede_finalizar', true);
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('registro');

    test()->withHeaders($headers)
        ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine.pdf', 200, 'application/pdf'),
        ])->assertOk();

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar')
        ->assertOk()
        ->assertJsonPath('data.registro_completo', true);

    test()->withHeaders($headers)->getJson('/api/v1/me')->assertJsonPath('registro_completo', true);

    $respuesta = test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($respuesta->json('codigo'))->not->toBe('registro_incompleto');

    // Cerrado el registro, cualquier cambio es por corrección.
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', ['datos' => ['profesion' => 'Otra']])
        ->assertUnprocessable();
});
