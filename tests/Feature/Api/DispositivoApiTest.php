<?php

use App\Models\MobileDevice;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function dispositivoHeaders(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('un usuario puede registrar un push token', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->withHeaders(dispositivoHeaders($usuario))
        ->postJson('/api/v1/dispositivos/push-token', [
            'token' => 'ExponentPushToken[abc123]',
            'platform' => 'android',
            'device_name' => 'Pixel de prueba',
        ])
        ->assertCreated();

    expect(MobileDevice::query()->where('push_token', 'ExponentPushToken[abc123]')->where('user_id', $usuario->id)->exists())->toBeTrue();
});

test('registrar el mismo token actualiza el dispositivo en vez de duplicarlo', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $headers = dispositivoHeaders($usuario);

    $this->withHeaders($headers)->postJson('/api/v1/dispositivos/push-token', [
        'token' => 'ExponentPushToken[dup]',
        'platform' => 'ios',
    ])->assertCreated();

    $this->withHeaders($headers)->postJson('/api/v1/dispositivos/push-token', [
        'token' => 'ExponentPushToken[dup]',
        'platform' => 'ios',
        'app_version' => '2.0.0',
    ])->assertCreated();

    expect(MobileDevice::query()->where('push_token', 'ExponentPushToken[dup]')->count())->toBe(1)
        ->and(MobileDevice::query()->where('push_token', 'ExponentPushToken[dup]')->first()->app_version)->toBe('2.0.0');
});

test('reasignar el mismo token a otra cuenta vía HTTP sigue funcionando (cambio de cuenta en el mismo telefono)', function () {
    // El log de auditoría de esta reasignación (PushTokenService::registrar())
    // se prueba a nivel de servicio en PushTokenServiceTest — aquí solo se
    // confirma el comportamiento observable end-to-end: nunca se bloquea.
    $cuentaA = User::factory()->create();
    $cuentaA->assignRole('colaborador');
    $cuentaB = User::factory()->create();
    $cuentaB->assignRole('colaborador');

    Sanctum::actingAs($cuentaA);
    $this->postJson('/api/v1/dispositivos/push-token', [
        'token' => 'ExponentPushToken[compartido]',
        'platform' => 'android',
    ])->assertCreated();

    Sanctum::actingAs($cuentaB);
    $this->postJson('/api/v1/dispositivos/push-token', [
        'token' => 'ExponentPushToken[compartido]',
        'platform' => 'android',
    ])->assertCreated();

    expect(MobileDevice::query()->where('push_token', 'ExponentPushToken[compartido]')->count())->toBe(1)
        ->and(MobileDevice::query()->where('push_token', 'ExponentPushToken[compartido]')->first()->user_id)->toBe($cuentaB->id);
});

test('un usuario puede revocar su propio dispositivo', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $dispositivo = MobileDevice::factory()->for($usuario, 'usuario')->create(['push_token' => 'token-a-revocar']);

    $this->withHeaders(dispositivoHeaders($usuario))
        ->deleteJson('/api/v1/dispositivos/push-token', ['token' => 'token-a-revocar'])
        ->assertOk();

    expect($dispositivo->fresh()->revoked_at)->not->toBeNull();
});

test('un usuario no puede revocar el dispositivo de otro', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $otro = User::factory()->create();
    MobileDevice::factory()->for($otro, 'usuario')->create(['push_token' => 'token-de-otro']);

    $this->withHeaders(dispositivoHeaders($usuario))
        ->deleteJson('/api/v1/dispositivos/push-token', ['token' => 'token-de-otro'])
        ->assertNotFound();

    expect(MobileDevice::query()->where('push_token', 'token-de-otro')->first()->revoked_at)->toBeNull();
});
