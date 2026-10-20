<?php

use App\Models\AltaDigital;
use App\Models\AltaDigitalDocumento;
use App\Models\Candidato;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
});

test('rh_admin puede crear un alta digital a partir de un candidato aprobado', function () {
    $candidato = Candidato::factory()->create(['estado' => 'autorizado_rh']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.store'), ['candidato_id' => $candidato->id])
        ->assertSessionHasNoErrors();

    $alta = AltaDigital::where('candidato_id', $candidato->id)->firstOrFail();

    expect($alta->nombre)->toBe($candidato->nombre)
        ->and($alta->correo)->toBe($candidato->correo)
        ->and($alta->token)->not->toBeEmpty();
});

test('un colaborador no puede crear altas digitales', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->post(route('rh.altas.store'), [])
        ->assertForbidden();
});

test('el candidato puede completar el wizard publico por token y enviarlo', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $tipoDoc = DocumentType::factory()->create(['aplica_alta' => true, 'activo' => true]);

    $alta = AltaDigital::factory()->create([
        'empresa_id' => $empresa->id,
        'sucursal_id' => $sucursal->id,
        'estado' => 'enviada',
        'token_expira_en' => now()->addDays(3),
    ]);

    $this->get(route('alta-publica.show', $alta->token))->assertOk();

    $this->put(route('alta-publica.datos-personales', $alta->token), [
        'nombre' => 'Juana',
        'apellidos' => 'Pérez',
        'telefono' => '8112345678',
        'correo' => 'juana.perez@example.com',
        'fecha_nacimiento' => now()->subYears(25)->toDateString(),
        'curp' => 'PEPJ990101MNLRRN01',
        'domicilio_calle' => 'Calle Falsa',
        'domicilio_numero_exterior' => '123',
        'domicilio_colonia' => 'Centro',
        'domicilio_cp' => '64000',
        'domicilio_municipio' => 'Monterrey',
        'domicilio_estado' => 'Nuevo León',
        'contacto_emergencia_nombre' => 'María Pérez',
        'contacto_emergencia_telefono' => '8187654321',
    ])->assertSessionHasNoErrors();

    $this->post(route('alta-publica.documentos', $alta->token), [
        'document_type_id' => $tipoDoc->id,
        'archivo' => UploadedFile::fake()->create('ine.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $this->put(route('alta-publica.consentimientos', $alta->token), [
        'aviso_privacidad_aceptado' => true,
        'consentimiento_datos_aceptado' => true,
        'firma' => 'data:image/png;base64,'.base64_encode('firma-de-prueba'),
    ])->assertSessionHasNoErrors();

    $this->post(route('alta-publica.enviar', $alta->token))->assertSessionHasNoErrors();

    $alta->refresh();

    expect($alta->estado->value)->toBe('enviada_por_candidato')
        ->and($alta->nombre)->toBe('Juana')
        ->and($alta->documentos()->count())->toBe(1)
        ->and($alta->aviso_privacidad_aceptado)->toBeTrue();
});

test('una firma que no es un data URL de imagen valido rechaza el consentimiento (422) y no lo marca aceptado', function () {
    $alta = AltaDigital::factory()->create([
        'estado' => 'en_captura',
        'token_expira_en' => now()->addDays(3),
        'aviso_privacidad_aceptado' => false,
        'consentimiento_datos_aceptado' => false,
        'firma_path' => null,
    ]);

    $this->put(route('alta-publica.consentimientos', $alta->token), [
        'aviso_privacidad_aceptado' => true,
        'consentimiento_datos_aceptado' => true,
        'firma' => 'no-soy-una-imagen',
    ])->assertSessionHasErrors('firma');

    $alta->refresh();

    expect($alta->aviso_privacidad_aceptado)->toBeFalse()
        ->and($alta->consentimiento_datos_aceptado)->toBeFalse()
        ->and($alta->firma_path)->toBeNull();
});

test('una firma con base64 corrupto tambien se rechaza (422) y no marca el consentimiento', function () {
    $alta = AltaDigital::factory()->create([
        'estado' => 'en_captura',
        'token_expira_en' => now()->addDays(3),
        'aviso_privacidad_aceptado' => false,
        'consentimiento_datos_aceptado' => false,
    ]);

    $this->put(route('alta-publica.consentimientos', $alta->token), [
        'aviso_privacidad_aceptado' => true,
        'consentimiento_datos_aceptado' => true,
        'firma' => 'data:image/png;base64,###no-es-base64###',
    ])->assertSessionHasErrors('firma');

    expect($alta->fresh()->aviso_privacidad_aceptado)->toBeFalse();
});

test('si un documento del alta no esta en el NAS, la conversion se revierte: no se crea colaborador ni usuario', function () {
    $tipoDoc = DocumentType::factory()->create(['aplica_alta' => true, 'activo' => true]);

    $alta = AltaDigital::factory()->create([
        'estado' => 'en_revision_rh',
        'correo' => 'sin-archivo@example.com',
    ]);

    // Documento cuyo archivo real NO existe en el disco fake: la copia
    // verificada al NAS debe fallar y revertir toda la conversión.
    AltaDigitalDocumento::factory()->create([
        'alta_digital_id' => $alta->id,
        'document_type_id' => $tipoDoc->id,
        'disk' => 'nas',
        'path' => 'altas/test/no-existe.pdf',
    ]);

    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)->post(route('rh.altas.aprobar', $alta));

    $alta->refresh();

    expect($alta->user_id)->toBeNull()
        ->and($alta->colaborador_id)->toBeNull()
        ->and(EmployeeDocument::count())->toBe(0);
});

test('si falla el envio del enlace de acceso, el colaborador y su usuario se conservan (no se revierte la conversion)', function () {
    $broker = Mockery::mock(PasswordBroker::class);
    $broker->shouldReceive('sendResetLink')->andThrow(new RuntimeException('SMTP caido'));
    Password::shouldReceive('broker')->andReturn($broker);

    $alta = AltaDigital::factory()->create([
        'estado' => 'en_revision_rh',
        'correo' => 'nuevo.acceso@example.com',
    ]);

    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.aprobar', $alta))
        ->assertSessionHasNoErrors();

    $alta->refresh();

    expect($alta->user_id)->not->toBeNull()
        ->and($alta->estado->value)->toBe('convertida_a_colaborador');

    $nuevo = User::find($alta->user_id);

    expect($nuevo)->not->toBeNull()
        ->and($nuevo->email)->toBe('nuevo.acceso@example.com')
        ->and($nuevo->colaborador)->not->toBeNull();
});

test('una liga expirada no permite capturar datos', function () {
    $alta = AltaDigital::factory()->create([
        'estado' => 'enviada',
        'token_expira_en' => now()->subDay(),
    ]);

    $this->get(route('alta-publica.show', $alta->token))->assertStatus(410);
});

test('rh_admin puede aprobar un alta enviada por el candidato y se crea el colaborador', function () {
    $vacante = Vacante::factory()->create(['estado' => 'en_revision']);
    $candidato = Candidato::factory()->create([
        'estado' => 'autorizado_rh',
        'vacante_id' => $vacante->id,
        'cv_disk' => 'nas',
        'cv_path' => 'candidatos/test/cv-original.pdf',
        'cv_original_name' => 'cv-original.pdf',
        'cv_mime' => 'application/pdf',
        'cv_size' => 2048,
    ]);
    Storage::disk('nas')->put($candidato->cv_path, 'contenido-del-cv');

    $alta = AltaDigital::factory()->create([
        'candidato_id' => $candidato->id,
        'vacante_id' => $vacante->id,
        'estado' => 'en_revision_rh',
        'correo' => 'nuevo.colaborador@example.com',
    ]);

    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.aprobar', $alta))
        ->assertSessionHasNoErrors();

    $alta->refresh();
    $candidato->refresh();
    $vacante->refresh();

    expect($alta->estado->value)->toBe('convertida_a_colaborador')
        ->and($alta->user_id)->not->toBeNull()
        ->and(User::find($alta->user_id)?->email)->toBe('nuevo.colaborador@example.com')
        ->and($candidato->estado->value)->toBe('contratado')
        ->and($vacante->estado->value)->toBe('cubierta');

    $documentoCv = EmployeeDocument::where('user_id', $alta->user_id)
        ->whereHas('tipo', fn ($q) => $q->where('clave', 'cv'))
        ->first();

    expect($documentoCv)->not->toBeNull()
        ->and(Storage::disk('nas')->exists($documentoCv->path))->toBeTrue();
});

test('al convertir un alta con foto, la foto se copia al expediente del colaborador', function () {
    $alta = AltaDigital::factory()->create([
        'estado' => 'en_revision_rh',
        'foto_disk' => 'nas',
        'foto_path' => 'altas/test/foto-original.jpg',
        'foto_original_name' => 'foto-original.jpg',
    ]);
    Storage::disk('nas')->put($alta->foto_path, 'contenido-de-la-foto');

    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.aprobar', $alta))
        ->assertSessionHasNoErrors();

    // El expediente (App\Models\Colaborador) es donde vive foto_path de
    // verdad; users.foto_path es una columna legacy que nadie más lee (ver
    // App\Services\Cumpleanos\MuroCumpleanosService, que sí lee del
    // Colaborador) — comparar contra el User daría siempre null.
    $colaborador = User::find($alta->refresh()->user_id)->colaborador;

    expect($colaborador->foto_path)->not->toBeNull()
        ->and($colaborador->foto_path)->not->toBe($alta->foto_path)
        ->and(Storage::disk('nas')->exists($colaborador->foto_path))->toBeTrue()
        ->and(Storage::disk('nas')->get($colaborador->foto_path))->toBe('contenido-de-la-foto');
});

test('rh_auxiliar no puede aprobar un alta digital', function () {
    $alta = AltaDigital::factory()->create(['estado' => 'en_revision_rh']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $this->actingAs($usuario)
        ->post(route('rh.altas.aprobar', $alta))
        ->assertForbidden();
});

test('rh puede rechazar un alta con motivo', function () {
    $alta = AltaDigital::factory()->create(['estado' => 'en_revision_rh']);
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $this->actingAs($usuario)
        ->post(route('rh.altas.rechazar', $alta), ['motivo_rechazo' => 'CURP inválida'])
        ->assertSessionHasNoErrors();

    expect($alta->fresh()->estado->value)->toBe('rechazada');
});
