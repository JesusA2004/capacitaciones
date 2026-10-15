<?php

use App\Enums\CategoriaNotificacion;
use App\Enums\TipoTarea;
use App\Jobs\SendExpoPushJob;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\NotificacionEntrega;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Notifications\Mobile\RhSolicitudCreadaNotification;
use App\Services\MobilePush\ExpoPushService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\MonitorNotificacionesService;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * Regla canónica (docs/NOTIFICACIONES_EVENTOS.md): todo evento que deja un
 * pendiente genera, desde el backend y en un solo flujo, la notificación en
 * base de datos (+ broadcast) y el push a los dispositivos del
 * destinatario; quien hizo la acción recibe su confirmación; la misma
 * transición nunca se avisa dos veces; y si algo se perdió, el
 * reconciliador lo repone una sola vez.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function fnUsuario(string $rol): User
{
    $usuario = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

function fnDispositivo(User $usuario, string $token): void
{
    MobileDevice::factory()->create(['user_id' => $usuario->id, 'push_token' => $token]);
}

test('CASO 1: colaborador crea una solicitud desde la app → confirmación a él, tarea + push al responsable, en la cola notifications', function () {
    Notification::fake();
    Queue::fake();

    $colaborador = fnUsuario('colaborador');
    $rh = fnUsuario('rh_admin');
    fnDispositivo($colaborador, 'ExponentPushToken[colaborador]');
    fnDispositivo($rh, 'ExponentPushToken[rh]');

    Sanctum::actingAs($colaborador);
    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Reparación del auto'])->assertCreated()->json('id');
    $solicitud = SolicitudInterna::query()->findOrFail($id);

    // Confirmación al actor (no se le excluye por haber originado la acción).
    Notification::assertSentTo($colaborador, PendienteRhNotification::class, fn ($n) => $n->toDatabase($colaborador)['categoria'] === 'confirmacion'
        && $n->toDatabase($colaborador)['titulo'] === 'Préstamo solicitado');
    // Tarea al responsable (payload histórico conservado para web/app).
    Notification::assertSentTo($rh, RhSolicitudCreadaNotification::class);
    Notification::assertNotSentTo($colaborador, RhSolicitudCreadaNotification::class);

    // Push a ambos dispositivos, en la cola dedicada.
    Queue::assertPushedOn('notifications', SendExpoPushJob::class, fn (SendExpoPushJob $job) => $job->token === 'ExponentPushToken[rh]' && $job->data['type'] === 'rh_solicitud');
    Queue::assertPushedOn('notifications', SendExpoPushJob::class, fn (SendExpoPushJob $job) => $job->token === 'ExponentPushToken[colaborador]' && $job->data['categoria'] === 'confirmacion');

    expect(NotificacionEntrega::query()->where('related_type', 'SolicitudInterna')->where('related_id', $solicitud->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(NotificacionEntrega::query()->where('user_id', $rh->id)->value('push_dispositivos'))->toBe(1);
});

test('CASO 2: RH crea una solicitud propia → recibe su confirmación; otro RH recibe la tarea; nadie recibe tarea sobre sí mismo', function () {
    Notification::fake();

    $rh = fnUsuario('rh_admin');
    $otroRh = fnUsuario('rh_admin');

    app(SolicitudesService::class)->crear($rh, ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Reparación del auto']);

    Notification::assertSentTo($rh, PendienteRhNotification::class, fn ($n) => $n->toDatabase($rh)['categoria'] === 'confirmacion');
    Notification::assertNotSentTo($rh, RhSolicitudCreadaNotification::class);
    Notification::assertSentTo($otroRh, RhSolicitudCreadaNotification::class);
});

test('actor que también es responsable recibe UN aviso combinado, nunca dos ni cero', function () {
    Notification::fake();

    $actor = fnUsuario('rh_admin');
    $otro = fnUsuario('rh_admin');
    $recurso = Colaborador::factory()->create();

    app(NotificacionEventosService::class)->registrarConPendiente(
        $actor,
        new Aviso('documento_subido', 'documento', 'Documento enviado', 'Quedó en revisión.', $recurso, 'registrado'),
        [$actor, $otro],
        new Aviso('documento_subido', 'rh_documento', 'Documento por revisar', 'Un colaborador subió un documento.', $recurso, 'revisar_documento'),
    );

    Notification::assertSentToTimes($actor, PendienteRhNotification::class, 1);
    Notification::assertSentTo($actor, PendienteRhNotification::class, fn ($n) => str_contains($n->toDatabase($actor)['titulo'], 'pendiente de tu resolución'));
    Notification::assertSentToTimes($otro, PendienteRhNotification::class, 1);
});

test('idempotencia: la misma transición pedida dos veces produce UNA notificación', function () {
    Notification::fake();

    $responsable = fnUsuario('rh_admin');
    $recurso = Colaborador::factory()->create();
    $aviso = new Aviso('evento_prueba', 'rh_pendiente', 'Pendiente', 'Algo por resolver.', $recurso, 'resolver');
    $servicio = app(NotificacionEventosService::class);

    expect($servicio->crearPendiente([$responsable], $aviso))->toHaveCount(1)
        ->and($servicio->crearPendiente([$responsable], $aviso))->toHaveCount(0);

    Notification::assertSentToTimes($responsable, PendienteRhNotification::class, 1);
    expect(NotificacionEntrega::query()->where('user_id', $responsable->id)->count())->toBe(1);
});

test('CASO 4: con el worker detenido el aviso espera en la cola notifications y sale al levantarlo (después del commit)', function () {
    config(['queue.default' => 'database']);

    $colaborador = fnUsuario('colaborador');
    $rh = fnUsuario('rh_admin');

    Sanctum::actingAs($colaborador);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Reparación del auto'])->assertCreated();

    // La solicitud ya existe; los avisos están encolados, no entregados.
    expect(SolicitudInterna::query()->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'notifications')->count())->toBeGreaterThan(0)
        ->and($rh->notifications()->count())->toBe(0);

    // "Supervisor levanta el worker". Corre DENTRO del proceso de Pest, así
    // que `memory_get_usage()` es el de toda la suite (fácilmente > 128 MB,
    // el --memory por omisión): el worker se detendría con
    // Worker::EXIT_MEMORY_LIMIT (12) tras el primer job, según cuánta
    // memoria haya acumulado la corrida. `--memory=0` desactiva ese corte
    // (Worker::memoryExceeded) y deja la prueba determinista; el límite de
    // memoria del worker real lo pone Supervisor, no esta prueba.
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--memory' => 0])->assertExitCode(0);

    expect($rh->notifications()->count())->toBe(1)
        ->and($colaborador->notifications()->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'notifications')->count())->toBe(0);
});

test('CASO 5: pendiente sin aviso → el reconciliador lo crea UNA vez; la segunda corrida no duplica', function () {
    $rh = fnUsuario('rh_admin');
    $persona = Colaborador::factory()->create();

    // Pendiente creado "por fuera" del flujo (sin aviso).
    $tarea = app(TareaService::class)->abrir(TipoTarea::ExpedienteIncompleto, $persona, [
        'titulo' => 'Expediente incompleto: prueba',
        'colaborador' => $persona,
        'permiso' => 'documentos.revisar',
        'accion' => 'revisar_expediente',
    ]);
    TareaRh::query()->whereKey($tarea?->id)->update(['created_at' => now()->subMinutes(5)]);

    expect($rh->notifications()->count())->toBe(0);

    $this->artisan('notificaciones:reconciliar')->assertExitCode(0);
    expect($rh->notifications()->count())->toBe(1);

    $this->artisan('notificaciones:reconciliar')->assertExitCode(0);
    expect($rh->notifications()->count())->toBe(1)
        ->and(NotificacionEntrega::query()->where('origen', 'reconciliador')->count())->toBeGreaterThanOrEqual(1);
});

test('el reconciliador no duplica un pendiente que el flujo normal ya avisó', function () {
    $colaborador = fnUsuario('colaborador');
    $rh = fnUsuario('rh_admin');

    Sanctum::actingAs($colaborador);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Reparación del auto'])->assertCreated();
    $antes = $rh->notifications()->count();

    $this->travel(2)->minutes();
    $this->artisan('notificaciones:reconciliar')->assertExitCode(0);

    expect($rh->notifications()->count())->toBe($antes);
});

test('CASO 7: Expo caído → la acción principal no falla y el push agotado queda como fallo visible', function () {
    config(['expo.enabled' => true]);
    Http::fake(['*' => Http::response('caído', 503)]);

    $colaborador = fnUsuario('colaborador');
    $rh = fnUsuario('rh_admin');
    fnDispositivo($rh, 'ExponentPushToken[rh]');

    Sanctum::actingAs($colaborador);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Reparación del auto'])->assertCreated();

    // La notificación de base de datos existe aunque Expo esté caído.
    expect(SolicitudInterna::query()->count())->toBe(1)
        ->and($rh->notifications()->count())->toBe(1);

    // Último intento sin respuesta de Expo: el job falla de verdad (failed_jobs).
    $job = new SendExpoPushJob('ExponentPushToken[rh]', 'Prueba', 'Cuerpo', ['type' => 'rh_solicitud', 'resource_id' => 1]);
    $job->tries = 1;

    expect(fn () => $job->handle(app(ExpoPushService::class), app(MonitorNotificacionesService::class)))
        ->toThrow(RuntimeException::class);
});

test('CASO 6: sin Reverb la notificación igual queda en base de datos (la web la ve por consulta)', function () {
    config(['broadcasting.default' => 'null']);

    $colaborador = fnUsuario('colaborador');
    $rh = fnUsuario('rh_admin');

    Sanctum::actingAs($colaborador);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Reparación del auto'])->assertCreated();

    $this->actingAs($rh)->getJson('/notificaciones')->assertOk();
    expect($rh->notifications()->count())->toBe(1);
});

test('la campana siempre muestra solo las propias, incluso a super_admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $otro = fnUsuario('colaborador');

    app(NotificacionEventosService::class)->avisarResultado($otro, new Aviso('x', 'notificacion', 'Solo para el otro', 'Privado.'));

    $respuesta = $this->actingAs($admin)->getJson('/notificaciones')->assertOk();

    expect(json_encode($respuesta->json()))->not->toContain('Solo para el otro');
});

test('«Notificaciones del sistema»: Sistemas/RH/super_admin la consultan (solo lectura); un colaborador recibe 403', function () {
    $otro = fnUsuario('colaborador');
    app(NotificacionEventosService::class)->avisarResultado($otro, new Aviso('x', 'notificacion', 'Aviso de otra persona', 'Texto.'));

    foreach (['sistemas', 'rh_admin', 'super_admin'] as $rol) {
        $this->actingAs(fnUsuario($rol))->get('/administracion/notificaciones')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Administracion/Notificaciones/Index'));
    }

    $this->actingAs($otro)->get('/administracion/notificaciones')->assertForbidden();

    // Consultar no la marca como leída.
    expect($otro->notifications()->first()?->read_at)->toBeNull();
});

test('diagnóstico y prueba manual de notificaciones: sin secretos y por los canales disponibles', function () {
    config(['broadcasting.connections.reverb.secret' => 'SECRETO-NO-MOSTRAR', 'broadcasting.connections.reverb.key' => 'LLAVE-NO-MOSTRAR']);
    $usuario = fnUsuario('colaborador');
    fnDispositivo($usuario, 'ExponentPushToken[NO-MOSTRAR]');

    $this->artisan('people:diagnostico-notificaciones')
        ->expectsOutputToContain('QUEUE_CONNECTION')
        ->doesntExpectOutputToContain('SECRETO-NO-MOSTRAR')
        ->doesntExpectOutputToContain('LLAVE-NO-MOSTRAR')
        ->doesntExpectOutputToContain('ExponentPushToken')
        ->assertExitCode(0);

    $this->artisan('people:probar-notificacion', ['--user' => $usuario->id])->assertExitCode(0);
    $this->artisan('people:probar-notificacion', ['--user' => $usuario->id])->assertExitCode(0);

    // Correrlo otra vez reemplaza la prueba anterior: no deja basura acumulada.
    expect($usuario->notifications()->count())->toBe(1)
        ->and(NotificacionEntrega::query()->where('user_id', $usuario->id)->where('categoria', CategoriaNotificacion::Prueba->value)->count())->toBe(1);
});
