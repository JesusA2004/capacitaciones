<?php

use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
});

test('rh_admin puede crear un alta digital a partir de un candidato aprobado', function () {
    $candidato = Candidato::factory()->create(['estado' => 'aprobado_rh']);
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
        'domicilio' => 'Calle Falsa 123',
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
        'estado' => 'aprobado_rh',
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

    $colaborador = User::find($alta->refresh()->user_id);

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
