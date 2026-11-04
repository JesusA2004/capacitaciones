<?php

use App\Enums\EstadoDocumento;
use App\Http\Middleware\ExigirExpedienteCompleto;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\ExpedienteIncompletoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * «Modo completar expediente» (ExpedienteIncompletoService + middleware
 * expediente.completo + guía «Tu siguiente paso»):
 *  - datos personales o documentos requeridos sin subir / rechazados → el
 *    autoservicio responde 423 «expediente_incompleto»; subir documentos sí;
 *  - documentos ya subidos en revisión de RH NO bloquean;
 *  - lo operativo (equipo/aprobaciones) nunca se bloquea;
 *  - bandera apagada o app sin la cabecera → no bloquea.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['features.bloqueo_expediente_incompleto' => true]);

    $this->ine = DocumentType::factory()->create(['nombre' => 'Identificación oficial', 'clave' => 'ine', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 1]);
    $this->acta = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento', 'clave' => 'acta_nacimiento', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 2]);
    // Lo genera RH: nunca cuenta como pendiente del colaborador.
    DocumentType::factory()->create(['nombre' => 'Contrato laboral', 'clave' => 'contrato', 'requerido' => true, 'aplica_alta' => false, 'activo' => true, 'orden' => 3]);

    $this->headers = [ExigirExpedienteCompleto::CABECERA_CLIENTE => '1'];
});

/** Datos personales completos (DatosFaltantesService::PERSONALES). */
function eiDatosCompletos(): array
{
    return [
        'genero' => 'femenino',
        'telefono' => '7770000000',
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

function eiUsuario(array $datos = [], string $rol = 'colaborador'): User
{
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo', ...$datos]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

function eiDocumento(User $usuario, DocumentType $tipo, EstadoDocumento $estado, ?string $motivo = null): EmployeeDocument
{
    return EmployeeDocument::factory()->create([
        'user_id' => $usuario->id,
        'colaborador_id' => $usuario->colaborador_id,
        'document_type_id' => $tipo->id,
        'status' => $estado->value,
        'rejection_reason' => $motivo,
    ]);
}

test('con datos y documentos faltantes el autoservicio responde 423 y subir un documento sí responde', function () {
    $usuario = eiUsuario();

    $respuesta = test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/solicitudes')
        ->assertStatus(423)
        ->assertJsonPath('code', 'expediente_incompleto')
        ->assertJsonPath('expediente.bloqueado', true)
        ->assertJsonPath('expediente.documentos_por_subir', 2);

    expect($respuesta->json('message'))->toContain('Completa tu expediente')
        ->and(collect($respuesta->json('expediente.motivos'))->pluck('clave')->all())->toContain('datos_personales', 'documentos_por_subir');

    // Listas legibles: «Te faltan N datos en M secciones» y una línea por sección.
    $datos = collect($respuesta->json('expediente.motivos'))->firstWhere('clave', 'datos_personales');
    expect($datos['titulo'])->toMatch('/^Te faltan \d+ datos en \d+ secciones$/')
        ->and($datos['detalle'])->not->toBeEmpty();

    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/colaborador/vacaciones')->assertStatus(423);

    // Lo que la guía necesita sí responde.
    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/colaborador/guia')
        ->assertOk()
        ->assertJsonPath('data.bloqueo.bloqueado', true)
        ->assertJsonPath('data.siguiente.clave', 'datos')
        ->assertJsonPath('data.siguiente.accion.app', 'completar-datos');
    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/mobile/bootstrap')
        ->assertOk()
        ->assertJsonPath('expediente_incompleto.bloqueado', true);
    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->ine->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine.pdf', 100, 'application/pdf'),
        ])->assertOk();

    // Proponer sus datos (actualización de datos) no se bloquea.
    $datosResp = test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)
        ->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'motivo' => '', 'datos' => ['profesion' => 'Ventas']]);
    expect($datosResp->status())->not->toBe(423);
});

test('documentos ya subidos en revisión de RH no bloquean', function () {
    $usuario = eiUsuario(eiDatosCompletos());
    eiDocumento($usuario, $this->ine, EstadoDocumento::EnRevision);
    eiDocumento($usuario, $this->acta, EstadoDocumento::Cargado);

    $estado = app(ExpedienteIncompletoService::class)->estado($usuario);
    expect($estado['bloqueado'])->toBeFalse()->and($estado['aplica'])->toBeTrue();

    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/solicitudes')->assertOk();

    $guia = test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/colaborador/guia')->assertOk();
    $documentos = collect($guia->json('data.pasos'))->where('grupo', 'documentos');
    expect($documentos->pluck('estado')->unique()->values()->all())->toBe(['en_revision'])
        ->and($guia->json('data.bloqueo.bloqueado'))->toBeFalse();
});

test('un documento rechazado bloquea y la guía lo pone primero con el motivo de RH', function () {
    $usuario = eiUsuario(eiDatosCompletos());
    eiDocumento($usuario, $this->ine, EstadoDocumento::Aprobado);
    eiDocumento($usuario, $this->acta, EstadoDocumento::Rechazado, 'La imagen está borrosa.');

    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/solicitudes')
        ->assertStatus(423)
        ->assertJsonPath('expediente.documentos_rechazados', 1)
        ->assertJsonPath('expediente.documentos_por_subir', 0);

    $guia = test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/colaborador/guia')->assertOk();
    $pasoActa = collect($guia->json('data.pasos'))->firstWhere('clave', sprintf('documento_%d', $this->acta->id));

    expect($pasoActa['estado'])->toBe('rechazado')
        ->and($pasoActa['motivo'])->toBe('La imagen está borrosa.')
        ->and($pasoActa['accion']['params']['document_type_id'])->toBe($this->acta->id)
        ->and($pasoActa['bloquea_app'])->toBeTrue();

    // Sin foto: la foto va antes que los documentos en el orden de la guía.
    expect($guia->json('data.siguiente.grupo'))->toBe('foto');
});

test('un gerente con expediente incompleto sigue atendiendo a su equipo', function () {
    $gerente = eiUsuario([], 'gerente_sucursal');

    test()->actingAs($gerente, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/solicitudes')->assertStatus(423);

    test()->actingAs($gerente, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/equipo')->assertOk();
    test()->actingAs($gerente, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/equipo/pendientes')->assertOk();
    test()->actingAs($gerente, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/equipo/aprobaciones/conteo')->assertOk();
});

test('no bloquea con la bandera apagada, sin la cabecera de la app ni a super_admin', function () {
    $usuario = eiUsuario();

    // APK anterior: no manda la cabecera.
    test()->actingAs($usuario, 'sanctum')->getJson('/api/v1/solicitudes')->assertOk();

    config(['features.bloqueo_expediente_incompleto' => false]);
    test()->actingAs($usuario, 'sanctum')->withHeaders($this->headers)->getJson('/api/v1/solicitudes')->assertOk();
    expect(app(ExpedienteIncompletoService::class)->estado($usuario)['aplica'])->toBeFalse();

    config(['features.bloqueo_expediente_incompleto' => true]);
    $admin = eiUsuario([], 'super_admin');
    expect(app(ExpedienteIncompletoService::class)->estado($admin)['bloqueado'])->toBeFalse();
});
