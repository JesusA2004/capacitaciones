<?php

use App\Enums\EstadoUsuario;
use App\Models\AltaDigital;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaDocumento;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Auditoría de seguridad del cierre de Fase 1 (endpoints y archivos).
|  1. Un archivo subido que pasa `mimes:pdf` pero declara text/html nunca se
|     sirve como HTML (XSS almacenado contra RH).
|  2. La firma del alta pública (sin sesión) debe ser realmente una imagen.
|  3. El alta digital respeta el alcance de sucursal también por ID directo.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

/** PDF «válido» para finfo (empieza con %PDF-) que un navegador también ejecuta como HTML. */
function auditoriaPoliglotaPdfHtml(): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pol');
    file_put_contents($ruta, "%PDF-1.4\n<html><body><script>fetch('/rh/usuarios')</script></body></html>\n%%EOF");

    // Cliente malicioso: declara text/html en la parte multipart.
    return new UploadedFile($ruta, 'comprobante.pdf', 'text/html', null, true);
}

test('el adjunto de una solicitud guarda el MIME real, no el que declara el cliente', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);

    app(SolicitudesService::class)->adjuntarDocumento($solicitud, auditoriaPoliglotaPdfHtml(), $colaborador);

    expect($solicitud->documentos()->firstOrFail()->mime)->toBe('application/pdf');
});

test('un adjunto con MIME text/html guardado nunca se sirve como HTML', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $colaborador->id]);
    Storage::disk('nas')->put('solicitudes/x/adjunto.pdf', 'contenido-de-prueba');
    $documento = SolicitudInternaDocumento::query()->create([
        'solicitud_interna_id' => $solicitud->id,
        'disk' => 'nas',
        'path' => 'solicitudes/x/adjunto.pdf',
        'original_name' => 'adjunto.pdf',
        'stored_name' => 'adjunto.pdf',
        'mime' => 'text/html',
        'size' => 25,
        'subido_por' => $colaborador->id,
    ]);

    $respuesta = $this->actingAs($colaborador)
        ->get(route('solicitudes.documentos.ver', [$solicitud, $documento]))
        ->assertOk();

    expect($respuesta->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($respuesta->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('un documento de expediente con MIME text/html (copiado del alta pública) nunca se sirve como HTML', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'mime' => 'text/html']);
    Storage::disk('nas')->put($documento->path, 'contenido-de-prueba');

    $respuesta = $this->actingAs($colaborador)->get(route('rh.documentos.descargar', $documento))->assertOk();

    expect($respuesta->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($respuesta->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('el alta pública rechaza una «firma» cuyo contenido no es imagen', function () {
    $alta = AltaDigital::factory()->create(['estado' => 'en_captura', 'token_expira_en' => now()->addDays(3)]);
    $html = 'data:image/png;base64,'.base64_encode('<html><script>alert(document.cookie)</script></html>');

    $this->put(route('alta-publica.consentimientos', $alta->token), [
        'aviso_privacidad_aceptado' => '1',
        'consentimiento_datos_aceptado' => '1',
        'firma' => $html,
    ])->assertSessionHasErrors('firma');

    expect($alta->fresh()->firma_path)->toBeNull()
        ->and($alta->fresh()->consentimiento_datos_aceptado)->toBeFalse();
});

test('el alta pública guarda el MIME real del documento subido', function () {
    $alta = AltaDigital::factory()->create(['estado' => 'en_captura', 'token_expira_en' => now()->addDays(3)]);
    $tipo = DocumentType::factory()->create(['aplica_alta' => true, 'activo' => true]);

    $this->post(route('alta-publica.documentos', $alta->token), [
        'document_type_id' => $tipo->id,
        'archivo' => auditoriaPoliglotaPdfHtml(),
    ])->assertSessionHasNoErrors();

    expect($alta->documentos()->firstOrFail()->mime)->toBe('application/pdf');
});

test('un gerente no abre ni aprueba por ID el alta digital de otra sucursal', function () {
    $suya = Sucursal::factory()->create();
    $ajena = Sucursal::factory()->create();
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $suya->id]);

    $altaAjena = AltaDigital::factory()->create(['sucursal_id' => $ajena->id, 'estado' => 'enviada_por_candidato', 'curp' => 'PEPJ990101MNLRRN01']);
    $altaPropia = AltaDigital::factory()->create(['sucursal_id' => $suya->id, 'estado' => 'enviada_por_candidato']);

    $this->actingAs($gerente)->get(route('rh.altas.show', $altaAjena))->assertForbidden();
    $this->actingAs($gerente)->post(route('rh.altas.aprobar', $altaAjena))->assertForbidden();
    $this->actingAs($gerente)->post(route('rh.altas.rechazar', $altaAjena), ['motivo_rechazo' => 'No.'])->assertForbidden();

    expect($altaAjena->fresh()->estado->value)->toBe('enviada_por_candidato');

    $this->actingAs($gerente)->get(route('rh.altas.show', $altaPropia))->assertOk();
});

test('un token de la app deja de servir en cuanto el colaborador queda inactivo, aunque nadie lo haya revocado', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $token = $usuario->createToken('app')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.me'))->assertOk();

    // Camino que cambia el estatus sin pasar por AccesoCuentaService (p. ej.
    // la migración inicial de expedientes marcando una baja).
    $usuario->colaborador->forceFill(['estatus' => EstadoUsuario::Inactivo])->save();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();

    expect($usuario->tokens()->count())->toBe(0);
});
