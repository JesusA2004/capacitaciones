<?php

use App\Models\MobileDevice;
use App\Models\User;
use App\Services\MobilePush\PushTokenService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('registrar el mismo token para una cuenta distinta deja rastro en el log, sin bloquear la reasignación', function () {
    $cuentaA = User::factory()->create();
    $cuentaB = User::factory()->create();
    $servicio = app(PushTokenService::class);

    $servicio->registrar($cuentaA, ['token' => 'tok-reasignado', 'platform' => 'android']);

    Log::spy();
    $dispositivo = $servicio->registrar($cuentaB, ['token' => 'tok-reasignado', 'platform' => 'android']);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $mensaje, array $contexto) => str_contains($mensaje, 'reasignado')
            && (int) $contexto['usuario_anterior_id'] === $cuentaA->id
            && (int) $contexto['usuario_nuevo_id'] === $cuentaB->id
            && ! str_contains(json_encode($contexto), 'tok-reasignado'), // nunca el token en claro en el log, solo su hash
    );

    expect($dispositivo->user_id)->toBe($cuentaB->id)
        ->and(MobileDevice::query()->where('push_token', 'tok-reasignado')->count())->toBe(1);
});

test('re-registrar el mismo token para la MISMA cuenta no genera ningun aviso', function () {
    $cuenta = User::factory()->create();
    $servicio = app(PushTokenService::class);

    $servicio->registrar($cuenta, ['token' => 'tok-mismo-dueno', 'platform' => 'android']);

    Log::spy();
    $servicio->registrar($cuenta, ['token' => 'tok-mismo-dueno', 'platform' => 'android', 'app_version' => '2.0.0']);

    Log::shouldNotHaveReceived('warning');
});
