<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoBaja;
use App\Enums\TipoTarea;
use App\Jobs\SendExpoPushJob;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\MobileDevice;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\MobilePush\ExpoPushService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Contratos que la app móvil consume tras la pasada final (docs de la app:
| docs/FINAL_MOBILE_AUDIT.md). Cada bloque cubre un gap G-x documentado.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

// ---------------------------------------------------------------- G-1

test('direccion y juridico entran a Gestion RH aunque no tengan rh.pendientes.ver', function (string $rol) {
    Sanctum::actingAs(clUsuario($rol));

    $respuesta = $this->getJson('/api/v1/mobile/bootstrap')->assertOk();

    expect($respuesta->json('capabilities.rh'))->toBeTrue()
        ->and($respuesta->json('user.permissions'))->not->toContain('rh.pendientes.ver')
        // Sin bandeja RH unificada: sus contadores RH son 0, nunca un 403.
        ->and($respuesta->json('counts.rh_pendientes'))->toBe(0);
})->with(['direccion', 'juridico']);

test('un colaborador o sistemas siguen sin Gestion RH', function (string $rol) {
    Sanctum::actingAs(clUsuario($rol));

    expect($this->getJson('/api/v1/mobile/bootstrap')->assertOk()->json('capabilities.rh'))->toBeFalse();
})->with(['colaborador', 'sistemas']);

// ---------------------------------------------------------------- counts.tasks

test('counts.tasks refleja las tareas abiertas reales del usuario', function () {
    $usuario = clUsuario('colaborador');
    $tareas = app(TareaService::class);
    $tareas->abrir(TipoTarea::ExpedienteIncompleto, $usuario->colaborador, ['usuario' => $usuario]);
    $resuelta = $tareas->abrir(TipoTarea::DocumentoRechazado, $usuario->colaborador, ['usuario' => $usuario]);
    $tareas->resolverManual($resuelta, $usuario);

    Sanctum::actingAs($usuario);

    expect($this->getJson('/api/v1/mobile/bootstrap')->assertOk()->json('counts.tasks'))->toBe(1)
        ->and($this->getJson('/api/v1/tareas')->assertOk()->json('meta.conteos.abiertas'))->toBe(1);
});

// ---------------------------------------------------------------- G-4

test('el detalle RH de un prestamo expone lo solicitado y el visto bueno antes de autorizar', function () {
    Notification::fake();
    $direccion = clUsuario('direccion');
    $jefe = clUsuario('jefe_directo');
    $colaborador = Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id]);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $cuenta->assignRole('colaborador');

    Sanctum::actingAs($cuenta);
    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'motivo' => 'Gastos médicos', 'monto_solicitado' => 15000, 'plazo_meses' => 12])
        ->assertCreated()->json('id');

    Sanctum::actingAs($direccion);
    $antes = $this->getJson("/api/v1/rh/solicitudes/{$id}")->assertOk();

    expect($antes->json('data.monto_solicitado'))->toEqual(15000)
        ->and($antes->json('data.plazo_solicitado'))->toBe(12)
        ->and($antes->json('data.prestamo.visto_bueno.requerido'))->toBeTrue()
        ->and($antes->json('data.prestamo.visto_bueno.estado'))->toBe('pendiente')
        // No se autoriza a ciegas: sin visto bueno el backend dice que no.
        ->and($antes->json('data.prestamo.puede_autorizar'))->toBeFalse()
        ->and($antes->json('data.prestamo.puede_rechazar'))->toBeTrue();

    Sanctum::actingAs($jefe);
    $this->postJson("/api/v1/equipo/solicitudes/{$id}/visto-bueno", ['aprobado' => true, 'comentario' => 'De acuerdo'])->assertOk();

    Sanctum::actingAs($direccion);
    $despues = $this->getJson("/api/v1/rh/solicitudes/{$id}")->assertOk();

    expect($despues->json('data.prestamo.visto_bueno.estado'))->toBe('aprobado')
        ->and($despues->json('data.prestamo.visto_bueno.jefe'))->toBe($jefe->nombreCompleto())
        ->and($despues->json('data.prestamo.visto_bueno.comentario'))->toBe('De acuerdo')
        ->and($despues->json('data.prestamo.puede_autorizar'))->toBeTrue();
});

test('una solicitud que no es prestamo no trae bloque de prestamo', function () {
    $rh = clUsuario('rh_admin');
    $solicitud = SolicitudInterna::factory()->create(['tipo' => 'permiso_con_goce']);

    Sanctum::actingAs($rh);

    expect($this->getJson("/api/v1/rh/solicitudes/{$solicitud->id}")->assertOk()->json('data.prestamo'))->toBeNull();
});

// ---------------------------------------------------------------- G-5

function auditDocumento(Colaborador $colaborador, EstadoFlujoDocumento $estado): GeneratedDocument
{
    return GeneratedDocument::factory()->create([
        'colaborador_id' => $colaborador->id,
        'titulo' => 'Contrato individual',
        'estado_flujo' => $estado->value,
    ]);
}

test('el titular consulta el detalle de su documento laboral con el contrato del listado', function () {
    $usuario = clUsuario('colaborador');
    $documento = auditDocumento($usuario->colaborador, EstadoFlujoDocumento::PendienteFirmaColaborador);

    Sanctum::actingAs($usuario);

    $listado = $this->getJson('/api/v1/colaborador/documentos-laborales')->assertOk()->json('data.0');
    $detalle = $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}")->assertOk()->json('data');

    expect($detalle)->toEqual($listado)
        ->and($detalle['id'])->toBe($documento->id);
});

test('nadie mas que el titular ve el detalle, ni siquiera RH por esta ruta', function () {
    $titular = clUsuario('colaborador');
    $documento = auditDocumento($titular->colaborador, EstadoFlujoDocumento::PendienteFirmaColaborador);

    Sanctum::actingAs(clUsuario('colaborador'));
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}")->assertNotFound();

    Sanctum::actingAs(clUsuario('rh_admin'));
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}")->assertNotFound();
});

test('un documento cancelado o en borrador ya no esta disponible para el titular', function (EstadoFlujoDocumento $estado) {
    $usuario = clUsuario('colaborador');
    $documento = auditDocumento($usuario->colaborador, $estado);

    Sanctum::actingAs($usuario);

    $this->getJson("/api/v1/colaborador/documentos-laborales/{$documento->id}")->assertNotFound();
})->with([EstadoFlujoDocumento::Cancelado, EstadoFlujoDocumento::Borrador]);

// ---------------------------------------------------------------- G-6

test('las notificaciones exponen related_type y accion para navegacion determinista', function () {
    $usuario = clUsuario('colaborador');
    $usuario->notify(new PendienteRhNotification('documento_firma_pendiente', 'Tienes un documento por firmar', 'Revisa y firma.', 'GeneratedDocument', 55, 'firmar_documento', 'alta'));

    Sanctum::actingAs($usuario);

    $data = $this->getJson('/api/v1/notificaciones')->assertOk()->json('data.0.data');

    expect($data)->toMatchArray([
        'type' => 'documento_firma_pendiente',
        'resource_id' => 55,
        'related_type' => 'GeneratedDocument',
        'accion' => 'firmar_documento',
    ]);
});

// ---------------------------------------------------------------- G-8

test('rh/cierres filtra por colaborador sin salir del alcance', function () {
    $rh = clUsuario('rh_admin');
    $a = Colaborador::factory()->create();
    $b = Colaborador::factory()->create();

    foreach ([$a, $b] as $colaborador) {
        CierreLaboral::query()->create([
            'colaborador_id' => $colaborador->id,
            'tipo_baja' => TipoBaja::Renuncia->value,
            'motivo' => 'Renuncia voluntaria',
            'fecha_efectiva' => now()->addWeek()->toDateString(),
            'estado' => EstadoCierreLaboral::Iniciado->value,
            'iniciado_por' => $rh->id,
        ]);
    }

    Sanctum::actingAs($rh);

    $ids = collect($this->getJson("/api/v1/rh/cierres?colaborador_id={$a->id}")->assertOk()->json('data'))->pluck('colaborador.id');

    expect($ids->all())->toBe([$a->id]);
});

// ---------------------------------------------------------------- G-2

test('rh/catalogos entrega catalogos para el alta solo a quien puede dar de alta', function () {
    Sanctum::actingAs(clUsuario('colaborador'));
    $this->getJson('/api/v1/rh/catalogos')->assertForbidden();

    Sanctum::actingAs(clUsuario('rh_admin'));
    $this->getJson('/api/v1/rh/catalogos')
        ->assertOk()
        ->assertJsonStructure(['data' => ['empresas', 'sucursales', 'departamentos', 'puestos', 'jefes', 'tipos_contratacion' => [['value', 'label', 'requiere_fecha_fin']], 'generos']]);
});

// ---------------------------------------------------------------- Push de prueba

test('push de prueba solo se envia a los dispositivos propios', function () {
    Bus::fake([SendExpoPushJob::class]);
    config(['expo.push_prueba' => true]);
    $usuario = clUsuario('colaborador');
    $otro = clUsuario('colaborador');
    MobileDevice::factory()->for($usuario, 'usuario')->create(['push_token' => 'ExponentPushToken[propio]']);
    MobileDevice::factory()->for($otro, 'usuario')->create(['push_token' => 'ExponentPushToken[ajeno]']);

    Sanctum::actingAs($usuario);
    // Aunque el cliente mande un token, se ignora: solo existen los de la cuenta.
    $this->postJson('/api/v1/dispositivos/push-prueba', ['token' => 'ExponentPushToken[ajeno]'])->assertStatus(202);

    Bus::assertDispatched(SendExpoPushJob::class, fn (SendExpoPushJob $job) => $job->token === 'ExponentPushToken[propio]' && $job->data['type'] === 'push_test' && $job->data['user_id'] === $usuario->id);
    Bus::assertNotDispatched(SendExpoPushJob::class, fn (SendExpoPushJob $job) => $job->token === 'ExponentPushToken[ajeno]');
});

test('push de prueba sin dispositivos o deshabilitado no envia nada', function () {
    Bus::fake([SendExpoPushJob::class]);
    $usuario = clUsuario('colaborador');
    Sanctum::actingAs($usuario);

    config(['expo.push_prueba' => true]);
    $this->postJson('/api/v1/dispositivos/push-prueba')->assertUnprocessable();

    config(['expo.push_prueba' => false]);
    MobileDevice::factory()->for($usuario, 'usuario')->create();
    $this->postJson('/api/v1/dispositivos/push-prueba')->assertForbidden();

    Bus::assertNothingDispatched();
});

// ---------------------------------------------------------------- Tokens invalidos

test('DeviceNotRegistered revoca el token para no seguir enviandole', function () {
    config(['expo.enabled' => true]);
    $dispositivo = MobileDevice::factory()->create(['push_token' => 'ExponentPushToken[muerto]']);
    Http::fake(['*' => Http::response(['data' => ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]])]);

    $resultado = app(ExpoPushService::class)->enviarConResultado('ExponentPushToken[muerto]', 'Hola', 'Prueba', ['type' => 'push_test']);

    expect($resultado)->toBe(ExpoPushService::RESULTADO_TOKEN_INVALIDO)
        ->and($dispositivo->fresh()->revoked_at)->not->toBeNull();
});

test('los errores de configuracion o limite no revocan el token', function (string $error, string $esperado) {
    config(['expo.enabled' => true]);
    $dispositivo = MobileDevice::factory()->create(['push_token' => 'ExponentPushToken[vivo]']);
    Http::fake(['*' => Http::response(['data' => ['status' => 'error', 'message' => $error, 'details' => ['error' => $error]]])]);

    expect(app(ExpoPushService::class)->enviarConResultado('ExponentPushToken[vivo]', 'Hola', 'Prueba'))->toBe($esperado)
        ->and($dispositivo->fresh()->revoked_at)->toBeNull();
})->with([
    ['InvalidCredentials', ExpoPushService::RESULTADO_ERROR],
    ['MessageTooBig', ExpoPushService::RESULTADO_ERROR],
    ['MessageRateExceeded', ExpoPushService::RESULTADO_REINTENTAR],
]);

test('los pendientes que piden accion usan el canal de prioridad alta', function () {
    config(['expo.enabled' => true]);
    Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'x']])]);

    app(ExpoPushService::class)->enviar('ExponentPushToken[a]', 'Firma', 'Pendiente', ['type' => 'documento_firma_pendiente']);
    app(ExpoPushService::class)->enviar('ExponentPushToken[b]', 'Recibo', 'Disponible', ['type' => 'recibo_nomina']);

    Http::assertSent(fn ($request) => $request['to'] === 'ExponentPushToken[a]' && $request['channelId'] === 'acciones' && $request['priority'] === 'high');
    Http::assertSent(fn ($request) => $request['to'] === 'ExponentPushToken[b]' && $request['channelId'] === 'default' && $request['priority'] === 'default');
});
