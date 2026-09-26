<?php

use App\Models\BirthdayGreeting;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Mobile\BirthdayGreetingNotification;
use App\Notifications\Mobile\BirthdayRhReminderNotification;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
    // "Hoy" se calcula en America/Mexico_City: se fija el reloj a mediodía
    // en México para que la prueba no falle de noche (cuando en UTC ya es
    // el día siguiente).
    Carbon::setTestNow(Carbon::parse('2026-09-25 18:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/**
 * La fecha de nacimiento y el estatus viven en Colaborador (la persona);
 * la notificación le llega a su cuenta (User) vinculada.
 */
function cumpleanieroConCuenta(array $atributos): User
{
    $colaborador = Colaborador::factory()->create($atributos);

    return User::factory()->create(['colaborador_id' => $colaborador->id]);
}

test('el command de felicitaciones notifica a los colaboradores que cumplen anios hoy y es idempotente', function () {
    Notification::fake();

    $cumpleaniero = cumpleanieroConCuenta(['fecha_nacimiento' => now()->subYears(30)]);
    $otro = cumpleanieroConCuenta(['fecha_nacimiento' => now()->addDays(5)->subYears(30)]);

    $this->artisan('cumpleanos:enviar-felicitaciones')->assertSuccessful();

    Notification::assertSentToTimes($cumpleaniero, BirthdayGreetingNotification::class, 1);
    Notification::assertNotSentTo($otro, BirthdayGreetingNotification::class);
    expect(BirthdayGreeting::where('colaborador_id', $cumpleaniero->colaborador_id)->count())->toBe(1);

    // Segunda corrida el mismo dia: no debe duplicar registro ni notificacion.
    Notification::fake();
    $this->artisan('cumpleanos:enviar-felicitaciones')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BirthdayGreeting::where('colaborador_id', $cumpleaniero->colaborador_id)->count())->toBe(1);
});

test('el command no felicita a colaboradores inactivos ni sin fecha de nacimiento', function () {
    Notification::fake();

    $inactivo = cumpleanieroConCuenta(['fecha_nacimiento' => now()->subYears(30), 'estatus' => 'inactivo']);
    $sinFecha = Colaborador::factory()->create(['fecha_nacimiento' => null]);

    $this->artisan('cumpleanos:enviar-felicitaciones')->assertSuccessful();

    Notification::assertNotSentTo($inactivo, BirthdayGreetingNotification::class);
    expect(BirthdayGreeting::where('colaborador_id', $sinFecha->id)->count())->toBe(0);
});

test('el recordatorio a rh notifica solo a quien tiene el permiso rh.cumpleanos.ver', function () {
    Notification::fake();

    $rhAdmin = User::factory()->create();
    $rhAdmin->assignRole('rh_admin');

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    Colaborador::factory()->create(['fecha_nacimiento' => now()->subYears(25)]);

    $this->artisan('cumpleanos:recordar-rh')->assertSuccessful();

    Notification::assertSentTo($rhAdmin, BirthdayRhReminderNotification::class);
    Notification::assertNotSentTo($colaborador, BirthdayRhReminderNotification::class);
});

test('el recordatorio a rh no envia nada si no hay cumpleanos hoy ni en los proximos 7 dias', function () {
    Notification::fake();

    $rhAdmin = User::factory()->create();
    $rhAdmin->assignRole('rh_admin');
    // Las cuentas creadas por la factory traen su propio Colaborador con
    // fecha al azar: se fija fuera de la ventana de 7 días.
    $rhAdmin->colaborador?->update(['fecha_nacimiento' => now()->addDays(60)->subYears(40)]);
    Colaborador::factory()->create(['fecha_nacimiento' => now()->addDays(20)->subYears(25)]);

    $this->artisan('cumpleanos:recordar-rh')->assertSuccessful();

    Notification::assertNothingSent();
});
