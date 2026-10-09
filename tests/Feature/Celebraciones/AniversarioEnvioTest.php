<?php

use App\Enums\TipoCelebracion;
use App\Jobs\SendExpoPushJob;
use App\Models\BirthdayGreeting;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\Sucursal;
use App\Models\User;
use App\Notifications\Mobile\CelebracionNotification;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Bug corregido: los aniversarios laborales se PREPARABAN pero nunca se
 * enviaban (auto_enviar_colaborador nacía en false y no había comando de
 * envío como el de cumpleaños). Ahora `aniversarios:enviar-felicitaciones`
 * corre a las 08:00 (CDMX) y avisa in-app + push, una sola vez.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    Bus::fake([SendExpoPushJob::class]);
    // 08:00 en México = 14:00 UTC del 25/09/2026.
    Carbon::setTestNow(Carbon::parse('2026-09-25 14:00:00', 'UTC'));
    $this->sucursal = Sucursal::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

function homenajeado(string $ingreso): User
{
    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => $ingreso, 'sucursal_principal_id' => test()->sucursal->id]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    MobileDevice::factory()->for($usuario, 'usuario')->create(['push_token' => 'ExponentPushToken[aniv-'.$usuario->id.']']);

    return $usuario;
}

test('el aniversario laboral de hoy se envía con notificación y push', function () {
    $usuario = homenajeado('2021-09-25');

    $this->artisan('aniversarios:enviar-felicitaciones')->assertSuccessful();

    $evento = BirthdayGreeting::query()->where('tipo', TipoCelebracion::AniversarioLaboral->value)->firstOrFail();
    expect($evento->anios)->toBe(5)
        ->and($evento->enviada_at)->not->toBeNull();

    Notification::assertSentTo($usuario, CelebracionNotification::class);
    Bus::assertDispatched(SendExpoPushJob::class, fn (SendExpoPushJob $job) => $job->token === "ExponentPushToken[aniv-{$usuario->id}]" && $job->data['type'] === 'aniversario_laboral' && $job->data['resource_id'] === $evento->id);
});

test('correrlo dos veces el mismo día no duplica el aviso ni el push', function () {
    homenajeado('2021-09-25');

    $this->artisan('aniversarios:enviar-felicitaciones')->assertSuccessful();
    $this->artisan('aniversarios:enviar-felicitaciones')->assertSuccessful();

    Bus::assertDispatchedTimes(SendExpoPushJob::class, 1);
    expect(BirthdayGreeting::query()->where('tipo', TipoCelebracion::AniversarioLaboral->value)->count())->toBe(1);
});

test('usa el día de México: a las 23:30 del 24 (05:30 UTC del 25) todavía no es el aniversario', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-25 05:30:00', 'UTC'));
    homenajeado('2021-09-25');

    $this->artisan('aniversarios:enviar-felicitaciones')->assertSuccessful();

    Bus::assertNotDispatched(SendExpoPushJob::class);
    Notification::assertNothingSent();
});

test('no felicita el año cero, ni a quien no cumple hoy, ni falla sin cuenta en la app', function () {
    homenajeado('2026-09-25');
    homenajeado('2021-09-26');
    Colaborador::factory()->create(['fecha_ingreso' => '2020-09-25', 'sucursal_principal_id' => $this->sucursal->id]);

    $this->artisan('aniversarios:enviar-felicitaciones')->assertSuccessful();

    Bus::assertNotDispatched(SendExpoPushJob::class);
    Notification::assertNothingSent();
});

test('el envío automático está programado a las 08:00 de México', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'aniversarios:enviar-felicitaciones'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('0 8 * * *')
        ->and($evento->timezone)->toBe('America/Mexico_City');
});
