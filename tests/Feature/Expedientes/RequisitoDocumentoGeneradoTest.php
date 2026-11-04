<?php

use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Http\Middleware\ExigirDocumentosPendientes;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Colaboradores\GuiaSiguientePasoService;
use App\Services\Expedientes\BloqueoDocumentosService;
use App\Services\Expedientes\DocumentosPendientesService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Expedientes\RequisitoDocumentoGeneradoService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Incorporacion\RegistroAppService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Requisitos documentales que PEOPLE GENERA (Solicitud de empleo, Estudio
| socioeconómico — RequisitoDocumentoGeneradoService): se cumplen con el
| documento generado (no cancelado) o con el escaneo que ya se había subido;
| nunca se piden subir, nunca bloquean la app ni cuentan para la carta
| compromiso, y web/API calculan el mismo avance.
|
| También: el bloqueo por documentos faltantes es del AUTOSERVICIO; quien
| tiene Gestión RH (gerente, RH…) sigue operando.
*/

beforeEach(function () {
    $this->seed([RolesYPermisosSeeder::class, DocumentTypeSeeder::class]);
    Storage::fake('nas');
    Notification::fake();
    $this->solicitud = DocumentType::query()->where('clave', 'solicitud_empleo')->firstOrFail();
});

/** Sube (registro directo) el documento vigente de un tipo. */
function rdgDocumento(Colaborador $colaborador, string $clave, EstadoDocumento $estado = EstadoDocumento::Aprobado): EmployeeDocument
{
    return EmployeeDocument::factory()->create([
        'colaborador_id' => $colaborador->id,
        'user_id' => User::factory()->create()->id,
        'document_type_id' => DocumentType::query()->where('clave', $clave)->value('id'),
        'status' => $estado->value,
    ]);
}

/** Todos los obligatorios aprobados salvo los que genera PEOPLE. */
function rdgTodoSubidoMenosGenerables(Colaborador $colaborador): void
{
    $generables = array_keys(app(RequisitoDocumentoGeneradoService::class)->tiposGenerables());

    foreach (DocumentType::query()->where('requerido', true)->where('activo', true)->get() as $tipo) {
        if (! in_array($tipo->clave, $generables, true)) {
            rdgDocumento($colaborador, $tipo->clave);
        }
    }
}

function rdgGenerar(Colaborador $colaborador, string $clave, ?EstadoFlujoDocumento $flujo = EstadoFlujoDocumento::Generado): GeneratedDocument
{
    return GeneratedDocument::factory()->create([
        'documentable_type' => $colaborador->getMorphClass(),
        'documentable_id' => $colaborador->id,
        'clave_plantilla' => $clave,
        'estado_flujo' => $flujo,
    ]);
}

test('la Solicitud de empleo es generable y nunca es un documento «por subir»', function () {
    $servicio = app(RequisitoDocumentoGeneradoService::class);
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo']);

    expect($servicio->tiposGenerables())->toMatchArray(['solicitud_empleo' => 'solicitud_empleo', 'estudio_socioeconomico' => 'estudio_socioeconomico'])
        ->and($servicio->esGenerable($this->solicitud))->toBeTrue()
        ->and(collect(app(DocumentosPendientesService::class)->pendientes($colaborador))->pluck('clave'))->not->toContain('solicitud_empleo');

    $generados = app(DocumentosPendientesService::class)->generadosPorSistema($colaborador);
    expect($generados)->toHaveCount(1)
        ->and($generados[0]['clave'])->toBe('solicitud_empleo')
        ->and($generados[0]['estado'])->toBe('por_generar')
        ->and($generados[0]['cumplido'])->toBeFalse()
        ->and($generados[0]['captura'])->toBe('rh')
        ->and($generados[0]['titulo'])->toBe('Lo prepara Recursos Humanos');
});

test('requisito cumplido con el documento generado (no cancelado), igual en web y API', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo']);
    rdgTodoSubidoMenosGenerables($colaborador);
    $expediente = app(ExpedienteService::class);
    $incorporacion = app(IncorporacionService::class);

    $antes = $expediente->estadoDocumental($colaborador);
    expect($antes['completo'])->toBeFalse()
        ->and(collect($antes['documentos'])->firstWhere('clave', 'solicitud_empleo')['cumplido_por_generado'])->toBeFalse();

    // Cancelado no cuenta.
    rdgGenerar($colaborador, 'solicitud_empleo', EstadoFlujoDocumento::Cancelado);
    expect($expediente->estadoDocumental($colaborador)['completo'])->toBeFalse();

    rdgGenerar($colaborador, 'solicitud_empleo');

    $web = $expediente->estadoDocumental($colaborador);
    $fila = collect($web['documentos'])->firstWhere('clave', 'solicitud_empleo');
    expect($web['completo'])->toBeTrue()
        ->and($web['porcentaje'])->toBe(100.0)
        ->and($fila['cumplido_por_generado'])->toBeTrue()
        ->and($fila['estado'])->toBe('pendiente');

    // Mismo avance en el resumen individual, el listado masivo y la API de la app.
    $resumen = $expediente->resumenCompletitud($colaborador);
    $lote = $expediente->resumenesCompletitud(collect([$colaborador->id]))[$colaborador->id];
    $app = $incorporacion->estadoIncorporacion($colaborador);
    $documentoApp = collect($app['documentos'])->firstWhere('tipo', 'solicitud_empleo');

    expect($resumen)->toBe($lote)
        ->and($resumen['porcentaje'])->toBe(100.0)
        ->and($app['progreso']['porcentaje'])->toBe(100)
        ->and($app['progreso']['completo'])->toBeTrue()
        ->and($app['estado'])->toBe('completo')
        ->and($documentoApp['puede_subir'])->toBeFalse()
        ->and($documentoApp['generado_por_sistema']['estado'])->toBe('generado')
        ->and($documentoApp['generado_por_sistema']['cumplido'])->toBeTrue();

    $usuario = clUsuarioDe($colaborador);
    $this->actingAs($usuario, 'sanctum')->getJson('/api/v1/colaborador/incorporacion')
        ->assertOk()
        ->assertJsonPath('progreso.porcentaje', 100);
});

test('sin generar, no bloquea la app, no cuenta para la carta compromiso y se presenta como «Lo prepara RH»', function () {
    $usuario = clUsuario('colaborador', ['estatus' => 'activo']);
    $colaborador = $usuario->colaborador;
    rdgTodoSubidoMenosGenerables($colaborador);
    $headers = [ExigirDocumentosPendientes::CABECERA_CLIENTE => '1'];

    expect(app(BloqueoDocumentosService::class)->estado($usuario))->toMatchArray(['activo' => false, 'pendientes' => 0]);

    $this->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/carta-compromiso')
        ->assertOk()
        ->assertJsonCount(0, 'data.documentos_pendientes')
        ->assertJsonPath('data.documentos_generados.0.clave', 'solicitud_empleo')
        ->assertJsonPath('data.documentos_generados.0.titulo', 'Lo prepara Recursos Humanos')
        ->assertJsonPath('data.bloqueo.activo', false);

    // No se puede comprometer en una carta: no es un documento que suba la persona.
    $this->actingAs($usuario, 'sanctum')->withHeaders($headers)
        ->postJson('/api/v1/colaborador/carta-compromiso/vista-previa', ['documentos' => [$this->solicitud->id]])
        ->assertUnprocessable();

    $documentoApp = collect(app(IncorporacionService::class)->estadoIncorporacion($colaborador)['documentos'])->firstWhere('tipo', 'solicitud_empleo');
    expect($documentoApp['puede_subir'])->toBeFalse()
        ->and($documentoApp['generado_por_sistema']['estado'])->toBe('por_generar')
        ->and($documentoApp['generado_por_sistema']['captura'])->toBe('rh');

    // Guía: paso en espera, sin «Subir documento» y sin bloquear.
    $paso = collect(app(GuiaSiguientePasoService::class)->guia($usuario, $colaborador)['pasos'])->firstWhere('clave', sprintf('documento_%d', $this->solicitud->id));
    expect($paso['estado'])->toBe('espera')
        ->and($paso['accion'])->toBeNull()
        ->and($paso['bloquea_app'])->toBeFalse();
});

test('el escaneo que ya se había subido sigue cumpliendo (no se invalida el historial)', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo']);
    rdgTodoSubidoMenosGenerables($colaborador);
    rdgDocumento($colaborador, 'solicitud_empleo', EstadoDocumento::Aprobado);

    $web = app(ExpedienteService::class)->estadoDocumental($colaborador);
    $fila = collect($web['documentos'])->firstWhere('clave', 'solicitud_empleo');
    $documentoApp = collect(app(IncorporacionService::class)->estadoIncorporacion($colaborador)['documentos'])->firstWhere('tipo', 'solicitud_empleo');

    expect($web['completo'])->toBeTrue()
        ->and($fila['estado'])->toBe('aprobado')
        ->and($fila['cumplido_por_generado'])->toBeFalse()
        ->and($documentoApp['generado_por_sistema']['estado'])->toBe('escaneo')
        ->and($documentoApp['generado_por_sistema']['cumplido'])->toBeTrue()
        ->and(app(DocumentosPendientesService::class)->pendientes($colaborador))->toBe([]);
});

test('registro en la app: se presenta como «Se genera con tus datos» y no se puede marcar «No tengo este documento»', function () {
    $rh = clUsuario('rh_admin');
    $colaborador = clColaboradorEnContratacion();
    ['token' => $tokenQr] = app(IncorporacionInvitacionService::class)->crear(['colaborador_id' => $colaborador->id], $rh);

    $token = (string) $this->postJson("/api/v1/incorporacion/invitaciones/{$tokenQr}/registrar", [
        'name' => 'Ana', 'apellidos' => 'Torres',
        'password' => 'Capacitacion2026!', 'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated()->json('token');
    $headers = ['Authorization' => 'Bearer '.$token, RegistroAppService::CABECERA_CLIENTE => '1'];

    $registro = $this->withHeaders($headers)->getJson('/api/v1/colaborador/registro')->assertOk();
    $fila = collect($registro->json('data.documentos.lista'))->firstWhere('tipo', 'solicitud_empleo');

    expect($fila['puede_subir'])->toBeFalse()
        ->and($fila['generado_por_sistema']['captura'])->toBe('registro')
        ->and($fila['generado_por_sistema']['titulo'])->toBe('Se genera con tus datos')
        ->and(implode(' ', $registro->json('data.pendientes')))->not->toContain('Solicitud de empleo (Formato interno)');

    $this->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', ['documentos_sin_tener' => [$this->solicitud->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documentos_sin_tener');
});

test('gerente con documentos faltantes: su autoservicio queda bloqueado, la operación no', function () {
    $headers = [ExigirDocumentosPendientes::CABECERA_CLIENTE => '1'];
    $gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo']);

    expect(app(BloqueoDocumentosService::class)->estado($gerente)['activo'])->toBeTrue();

    foreach (['/api/v1/equipo', '/api/v1/tareas', '/api/v1/equipo/aprobaciones/conteo'] as $ruta) {
        $respuesta = $this->actingAs($gerente, 'sanctum')->withHeaders($headers)->getJson($ruta);
        expect($respuesta->json('code'))->not->toBe('documentos_pendientes')
            ->and($respuesta->status())->toBe(200);
    }

    $this->actingAs($gerente, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes')
        ->assertForbidden()
        ->assertJsonPath('code', 'documentos_pendientes');

    // Un colaborador sin Gestión RH sigue limitado a sus documentos.
    $colaborador = clUsuario('colaborador', ['estatus' => 'activo']);
    $this->actingAs($colaborador, 'sanctum')->withHeaders($headers)->getJson('/api/v1/tareas')
        ->assertForbidden()
        ->assertJsonPath('code', 'documentos_pendientes');
});
