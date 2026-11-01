<?php

use App\Enums\AlcanceAviso;
use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoReciboNomina;
use App\Models\ActaAdministrativa;
use App\Models\ActaAnexo;
use App\Models\Aviso;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/*
| Bandera por documento y consulta en línea: `puede_descargar` nunca da más que
| la Policy, sin bandera la ruta `/descargar` también responde 403, y `/ver` se
| autoriza con `ver`, sin exigir `descargar`.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $this->titular = clUsuarioDe($this->persona);
});

function fdDocumento(Colaborador $persona, array $atributos = []): GeneratedDocument
{
    Storage::disk('nas')->put('documentos/prueba.pdf', '%PDF-1.4 prueba');

    return GeneratedDocument::query()->create([
        'colaborador_id' => $persona->id,
        'titulo' => 'Documento de prueba',
        'original_name' => 'prueba.pdf',
        'generated_name' => 'prueba.pdf',
        'disk' => 'nas',
        'path' => 'documentos/prueba.pdf',
        'mime' => 'application/pdf',
        'checksum' => 'hash-1',
        'estado_flujo' => EstadoFlujoDocumento::Generado,
        'categoria' => CategoriaDocumento::Contratos,
        ...$atributos,
    ]);
}

/** Rol editado en «Roles y permisos»: ve contratos, no los descarga. */
function fdSoloConsultaContratos(): User
{
    Role::findByName('auditor')->revokePermissionTo('contratos.descargar');

    return clUsuario('auditor');
}

test('el titular recibe puede_descargar y sin bandera en el listado RH no se manda', function () {
    $documento = fdDocumento($this->persona);

    Sanctum::actingAs($this->titular);
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}")
        ->assertOk()->assertJsonPath('data.puede_descargar', true)->assertJsonPath('data.puede_descargar_word', false);

    expect(app(DocumentoLaboralConsultaService::class)->aArray($documento))->not->toHaveKey('puede_descargar');
});

test('contrato ajeno: quien ve sin contratos.descargar consulta en /ver y no descarga', function () {
    $documento = fdDocumento($this->persona);
    Sanctum::actingAs(fdSoloConsultaContratos());
    $this->getJson("/api/v1/rh/documentos-laborales/{$documento->id}")->assertOk()->assertJsonPath('data.puede_descargar', false);
    $this->get("/api/v1/rh/documentos-laborales/{$documento->id}/descargar")->assertForbidden();

    $ver = $this->get("/api/v1/rh/documentos-laborales/{$documento->id}/ver")->assertOk();
    expect($ver->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($ver->headers->get('Cache-Control'))->toContain('no-store')
        ->and($documento->refresh()->descargas)->toBe(0);
});

test('sin ver no hay consulta en línea y sin token responde 401', function () {
    $documento = fdDocumento($this->persona);

    $this->getJson("/api/v1/rh/documentos-laborales/{$documento->id}/ver")->assertUnauthorized();
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}/ver")->assertUnauthorized();

    Sanctum::actingAs(clUsuario('colaborador'));
    $this->getJson("/api/v1/rh/documentos-laborales/{$documento->id}/ver")->assertForbidden();
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}/ver")->assertForbidden();
});

test('firma: exige la consulta en línea, la apertura y acepto; no marca leído sola', function () {
    $documento = fdDocumento($this->persona, ['estado_flujo' => EstadoFlujoDocumento::PendienteFirmaColaborador, 'requiere_firma_digital' => true]);
    Sanctum::actingAs($this->titular);
    $firmar = "/api/v1/colaborador/documentos-laborales/{$documento->id}/firmar";
    $apertura = "/api/v1/colaborador/documentos-laborales/{$documento->id}/apertura";

    // Sin abrirlo no firma, aunque acepte.
    $this->postJson($firmar, ['acepto' => true])->assertUnprocessable()->assertJsonValidationErrors('documento');
    // Reportar el dibujo sin haber pedido el PDF no cuenta.
    $this->postJson($apertura)->assertUnprocessable();
    // Descargar el PDF con /descargar tampoco cuenta.
    $this->get("/api/v1/colaborador/documentos-laborales/{$documento->id}/descargar")->assertOk();
    $this->postJson($apertura)->assertUnprocessable();

    // Pedirlo sin confirmar el dibujo no habilita la firma ni cambia el estado.
    $this->get("/api/v1/colaborador/documentos-laborales/{$documento->id}/ver")->assertOk();
    $this->postJson($firmar, ['acepto' => true])->assertUnprocessable();
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::PendienteFirmaColaborador)
        ->and($documento->firmado_digital_en)->toBeNull();

    $this->postJson($apertura)->assertOk();
    expect($documento->refresh()->firmado_digital_en)->toBeNull();

    // La apertura no sustituye a la aceptación.
    $this->postJson($firmar, ['acepto' => false])->assertUnprocessable();
    $this->postJson($firmar, ['acepto' => true])->assertOk();
    expect($documento->refresh()->firmado_digital_en)->not->toBeNull();
});

test('firma: la apertura de otra versión del archivo no sirve', function () {
    $documento = fdDocumento($this->persona, ['estado_flujo' => EstadoFlujoDocumento::PendienteFirmaColaborador, 'requiere_firma_digital' => true]);
    Sanctum::actingAs($this->titular);

    $this->get("/api/v1/colaborador/documentos-laborales/{$documento->id}/ver")->assertOk();
    $this->postJson("/api/v1/colaborador/documentos-laborales/{$documento->id}/apertura")->assertOk();
    $documento->update(['checksum' => 'hash-2']);

    $this->postJson("/api/v1/colaborador/documentos-laborales/{$documento->id}/firmar", ['acepto' => true])->assertUnprocessable();
});

test('apertura: solo el titular; RH viendo /ver no deja evidencia', function () {
    $documento = fdDocumento($this->persona, ['estado_flujo' => EstadoFlujoDocumento::PendienteFirmaColaborador, 'requiere_firma_digital' => true]);

    Sanctum::actingAs(clUsuario('rh_admin'));
    $this->get("/api/v1/rh/documentos-laborales/{$documento->id}/ver")->assertOk();
    $this->postJson("/api/v1/colaborador/documentos-laborales/{$documento->id}/apertura")->assertForbidden();
    expect($documento->eventos()->count())->toBe(0);
});

test('anexo de acta: con actas.ver consulta en línea; descargar solo con la bandera', function () {
    $acta = ActaAdministrativa::factory()->create(['colaborador_id' => $this->persona->id, 'creado_por' => clUsuario('rh_admin')->id]);
    Storage::disk('nas')->put('actas/a.pdf', '%PDF-1.4 anexo');
    $anexo = ActaAnexo::query()->create(['acta_administrativa_id' => $acta->id, 'disk' => 'nas', 'path' => 'actas/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10]);

    // Seeder: gerente_sucursal tiene actas.ver sin actas.descargar.
    Sanctum::actingAs(clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]));
    $this->getJson("/api/v1/rh/actas/{$acta->id}")->assertOk()->assertJsonPath('data.anexos.0.puede_descargar', false);
    $this->get("/api/v1/rh/actas/{$acta->id}/anexos/{$anexo->id}")->assertForbidden();
    expect($this->get("/api/v1/rh/actas/{$acta->id}/anexos/{$anexo->id}/ver")->assertOk()->headers->get('Content-Disposition'))->toStartWith('inline');

    Sanctum::actingAs(clUsuario('rh_admin'));
    $this->getJson("/api/v1/rh/actas/{$acta->id}")->assertJsonPath('data.anexos.0.puede_descargar', true);
    $this->get("/api/v1/rh/actas/{$acta->id}/anexos/{$anexo->id}")->assertOk();

    Sanctum::actingAs(clUsuario('colaborador'));
    $this->get("/api/v1/rh/actas/{$acta->id}/anexos/{$anexo->id}/ver")->assertForbidden();
});

test('matriz por rol sobre un documento laboral: el flag nunca dice más que la ruta', function (string $rol, bool $esperado) {
    $documento = fdDocumento($this->persona, ['categoria' => CategoriaDocumento::Contratos]);
    $cuenta = clUsuario($rol, ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    Sanctum::actingAs($cuenta);

    expect($cuenta->can('descargar', $documento))->toBe($esperado);
    $this->get("/api/v1/rh/documentos-laborales/{$documento->id}/descargar")->assertStatus($esperado ? 200 : 403);
})->with([
    'rh_admin' => ['rh_admin', true],
    'rh_auxiliar' => ['rh_auxiliar', true],
    'auditor' => ['auditor', true],
    'direccion' => ['direccion', true],
    'juridico' => ['juridico', true],
    'gerente_sucursal' => ['gerente_sucursal', false],
    'gerente' => ['gerente', false],
    'gerente_regional' => ['gerente_regional', false],
    'sistemas' => ['sistemas', false],
    'contraloria' => ['contraloria', false],
    'colaborador ajeno' => ['colaborador', false],
]);

test('Contraloría y Jurídico entran a Gestión RH sin ganar permisos', function (string $rol) {
    $cuenta = clUsuario($rol);
    $antes = $cuenta->getAllPermissions()->pluck('name')->sort()->values()->all();

    Sanctum::actingAs($cuenta);
    $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertJsonPath('capabilities.rh', true);
    $this->getJson('/api/v1/rh/bajas')->assertOk();
    $this->getJson('/api/v1/rh/pendientes')->assertForbidden();

    expect($cuenta->refresh()->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($antes);
})->with(['juridico', 'contraloria']);

test('recibo: bandera real para el titular con la función activa', function () {
    config(['features.recibos_colaborador' => true]);
    Storage::disk('nas')->put('recibos/r.pdf', '%PDF-1.4');
    $recibo = ReciboNomina::factory()->create(['colaborador_id' => $this->persona->id, 'estado' => EstadoReciboNomina::Emitido->value, 'pdf_disk' => 'nas', 'pdf_path' => 'recibos/r.pdf']);

    Sanctum::actingAs($this->titular);
    $this->getJson("/api/v1/colaborador/recibos/{$recibo->id}")->assertOk()->assertJsonPath('data.puede_descargar', true);
});

test('aviso: el destinatario recibe la bandera del adjunto', function () {
    $aviso = Aviso::factory()->create([
        'alcance' => AlcanceAviso::Todos,
        'archivo_disk' => 'nas',
        'archivo_path' => 'avisos/a.pdf',
        'archivo_mime' => 'application/pdf',
        'archivo_nombre' => 'a.pdf',
        'archivo_size' => 10,
    ]);

    Sanctum::actingAs($this->titular);
    $this->getJson("/api/v1/avisos/{$aviso->id}")->assertOk()->assertJsonPath('data.archivo.puede_descargar', true);
});

test('candidato: CV y evidencias siguen a CandidatoPolicy::view', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'cv_path' => 'cv/c.pdf', 'cv_original_name' => 'c.pdf']);
    $rh = clUsuario('rh_admin');
    $psicometrica = $candidato->psicometricas()->create(['enviada_por' => $rh->id, 'enviada_en' => now()]);
    $psicometrica->evidencias()->create(['candidato_id' => $candidato->id, 'disk' => 'nas', 'tipo' => 'documento', 'path' => 'cv/e.pdf', 'original_name' => 'e.pdf', 'mime' => 'application/pdf', 'size' => 10, 'subida_por' => $rh->id]);

    Sanctum::actingAs($rh);
    $this->getJson("/api/v1/rh/candidatos/{$candidato->id}")->assertOk()->assertJsonPath('data.candidato.puede_descargar_cv', true)
        ->assertJsonPath('data.candidato.psicometricas.0.evidencias.0.puede_descargar', true);

    Sanctum::actingAs(clUsuario('colaborador'));
    $this->getJson("/api/v1/rh/candidatos/{$candidato->id}")->assertForbidden();
});
