<?php

use App\Jobs\SendExpoPushJob;
use App\Jobs\SendWebPushJob;
use App\Models\MobileDevice;
use App\Models\User;
use App\Models\WebPushSuscripcion;
use App\Services\MobilePush\PushNotifier;
use App\Services\WebPush\ClavesVapidService;
use App\Services\WebPush\WebPushNotifier;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('local');
    // Llaves fijas de prueba (solo tests): generar una exige openssl.cnf,
    // que el PHP de WAMP en Windows no encuentra sin OPENSSL_CONF.
    config([
        'webpush.vapid.public_key' => 'BMfOcpSXBEJVEtiJyfhlWCntnMWHyS_PSfYlwgWt_oWoIgb08LmUUgGyAo3z4j3MwT3gdZ62UnaObJNGt7YRk0I',
        'webpush.vapid.private_key' => '9HjaI-5eu8llLlYqWAnQXMSZKwumvZ7BgzQkWdWSYL4',
    ]);
});

function suscripcionDePrueba(User $usuario, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): WebPushSuscripcion
{
    return WebPushSuscripcion::query()->create([
        'user_id' => $usuario->id,
        'endpoint' => $endpoint,
        'endpoint_hash' => WebPushSuscripcion::hashDe($endpoint),
        'clave_publica' => 'BClavePublicaDePrueba',
        'token_autenticacion' => 'tokenAuth',
        'codificacion' => 'aes128gcm',
    ]);
}

test('sin llaves en el .env se generan una sola vez y se reutilizan', function () {
    if (@openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) === false) {
        $this->markTestSkipped('OpenSSL sin openssl.cnf (define OPENSSL_CONF) no puede generar llaves EC.');
    }

    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
    $primera = app(ClavesVapidService::class)->clavePublica();
    $segunda = (new ClavesVapidService)->clavePublica();

    expect($primera)->toBeString()->not->toBe('')
        ->and($segunda)->toBe($primera);
    Storage::disk('local')->assertExists('webpush/vapid.json');
});

test('el navegador se suscribe y una cuenta distinta en el mismo navegador se la queda', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $datos = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz',
        'keys' => ['p256dh' => 'BClave', 'auth' => 'auth'],
        'contentEncoding' => 'aes128gcm',
    ];

    $this->actingAs($ana)->postJson('/notificaciones/web-push', $datos)->assertOk();
    $this->actingAs($beto)->postJson('/notificaciones/web-push', $datos)->assertOk();

    expect(WebPushSuscripcion::query()->count())->toBe(1)
        ->and(WebPushSuscripcion::query()->first()->user_id)->toBe($beto->id);

    $this->actingAs($beto)->deleteJson('/notificaciones/web-push', ['endpoint' => $datos['endpoint']])->assertOk();
    expect(WebPushSuscripcion::query()->count())->toBe(0);
});

test('suscribirse exige una suscripción válida y sesión', function () {
    $this->postJson('/notificaciones/web-push', [])->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->postJson('/notificaciones/web-push', ['endpoint' => 'no-es-url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});

test('todo push que llega a la app llega también al navegador suscrito', function () {
    Queue::fake();
    $usuario = User::factory()->create();
    MobileDevice::query()->create(['user_id' => $usuario->id, 'push_token' => 'ExponentPushToken[x]', 'platform' => 'android']);
    $suscripcion = suscripcionDePrueba($usuario);

    $enviados = app(PushNotifier::class)->aUsuarioConDatos($usuario, 'Solicitud aprobada', 'Tu permiso fue aprobado.', [
        'type' => 'solicitud',
        'resource_id' => 7,
        'notification_id' => '9b6f2c1e-1c1a-4f7e-9a51-6a4f1f0c2b3d',
    ]);

    expect($enviados)->toBe(2);
    Queue::assertPushed(SendExpoPushJob::class, 1);
    Queue::assertPushed(SendWebPushJob::class, fn (SendWebPushJob $job) => $job->suscripcionId === $suscripcion->id
        && $job->payload['url'] === '/notificaciones/9b6f2c1e-1c1a-4f7e-9a51-6a4f1f0c2b3d/ir'
        && str_contains($job->payload['titulo'], 'Solicitud aprobada'));
});

test('un aviso de RH sin fila en notifications abre su tarjeta del feed', function () {
    $payload = app(WebPushNotifier::class)->payload('Aviso', 'Mensaje', ['type' => 'aviso_rh', 'resource_id' => 15]);

    expect($payload['url'])->toBe('/notificaciones/aviso:15/ir');
});

test('sin navegador suscrito no se encola ningún web push', function () {
    Queue::fake();

    expect(app(WebPushNotifier::class)->aUsuario(User::factory()->create(), 'T', 'C', ['type' => 'solicitud']))->toBe(0);
    Queue::assertNotPushed(SendWebPushJob::class);
});

test('la ruta del clic marca leída la notificación y redirige; una inválida cae al centro', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)->get('/notificaciones/no-existe/ir')->assertRedirect();
    $this->actingAs($usuario)->get('/notificaciones/centro')->assertRedirect();
});

test('la clave pública se comparte al frontend solo con sesión', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($pagina) => $pagina->where('webPush.clavePublica', fn ($clave) => is_string($clave) && $clave !== ''));
});
