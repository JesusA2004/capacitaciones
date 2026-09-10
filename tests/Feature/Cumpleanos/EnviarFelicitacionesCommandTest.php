<?php

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Notifications\Mobile\BirthdayGreetingNotification;
use App\Notifications\Mobile\BirthdayRhReminderNotification;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
});

test('el command de felicitaciones notifica a los colaboradores que cumplen anios hoy y es idempotente', function () {
    Notification::fake();

    $cumpleaniero = User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);
    $otro = User::factory()->create(['fecha_nacimiento' => now()->addDays(5)->subYears(30)]);

    $this->artisan('cumpleanos:enviar-felicitaciones')->assertSuccessful();

    Notification::assertSentToTimes($cumpleaniero, BirthdayGreetingNotification::class, 1);
    Notification::assertNotSentTo($otro, BirthdayGreetingNotification::class);
    expect(BirthdayGreeting::where('user_id', $cumpleaniero->id)->count())->toBe(1);

    // Segunda corrida el mismo dia: no debe duplicar registro ni notificacion.
    Notification::fake();
    $this->artisan('cumpleanos:enviar-felicitaciones')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BirthdayGreeting::where('user_id', $cumpleaniero->id)->count())->toBe(1);
});

test('el command no felicita a colaboradores inactivos ni sin fecha de nacimiento', function () {
    Notification::fake();

    $inactivo = User::factory()->create(['fecha_nacimiento' => now()->subYears(30), 'estatus' => 'inactivo']);
    $sinFecha = User::factory()->create(['fecha_nacimiento' => null]);

    $this->artisan('cumpleanos:enviar-felicitaciones')->assertSuccessful();

    Notification::assertNotSentTo($inactivo, BirthdayGreetingNotification::class);
    expect(BirthdayGreeting::where('user_id', $sinFecha->id)->count())->toBe(0);
});

test('el recordatorio a rh notifica solo a quien tiene el permiso rh.cumpleanos.ver', function () {
    Notification::fake();

    $rhAdmin = User::factory()->create();
    $rhAdmin->assignRole('rh_admin');

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    User::factory()->create(['fecha_nacimiento' => now()->subYears(25)]);

    $this->artisan('cumpleanos:recordar-rh')->assertSuccessful();

    Notification::assertSentTo($rhAdmin, BirthdayRhReminderNotification::class);
    Notification::assertNotSentTo($colaborador, BirthdayRhReminderNotification::class);
});

test('el recordatorio a rh no envia nada si no hay cumpleanos hoy ni en los proximos 7 dias', function () {
    Notification::fake();

    $rhAdmin = User::factory()->create();
    $rhAdmin->assignRole('rh_admin');
    User::factory()->create(['fecha_nacimiento' => now()->addDays(20)->subYears(25)]);

    $this->artisan('cumpleanos:recordar-rh')->assertSuccessful();

    Notification::assertNothingSent();
});
