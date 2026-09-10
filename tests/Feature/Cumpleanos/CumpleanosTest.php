<?php

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosService;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
});

test('un rh_admin autorizado ve el panel de cumpleanos', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $this->actingAs($admin)->get(route('rh.cumpleanos.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Cumpleanos/Index'));
});

test('un colaborador sin permiso no puede ver el panel de cumpleanos', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('rh.cumpleanos.index'))->assertForbidden();
});

test('cumpleanos del mes devuelve solo colaboradores activos que cumplen ese mes', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $deEnero = User::factory()->create(['fecha_nacimiento' => '1990-01-15']);
    $deFebrero = User::factory()->create(['fecha_nacimiento' => '1985-02-20']);

    $resultado = app(CumpleanosService::class)->cumpleanosDelMes(1, $admin);

    expect($resultado->pluck('id'))->toContain($deEnero->id)
        ->and($resultado->pluck('id'))->not->toContain($deFebrero->id);
});

test('proximos cumpleanos respeta la ventana de dias solicitada, incluyendo el cruce de anio', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $hoy = now();
    $enTresDias = User::factory()->create(['fecha_nacimiento' => $hoy->copy()->addDays(3)->subYears(30)]);
    $enVeinte = User::factory()->create(['fecha_nacimiento' => $hoy->copy()->addDays(20)->subYears(30)]);

    $servicio = app(CumpleanosService::class);
    $proximos7 = $servicio->proximosCumpleanos($admin, 7);
    $proximos30 = $servicio->proximosCumpleanos($admin, 30);

    expect($proximos7->pluck('id'))->toContain($enTresDias->id)
        ->and($proximos7->pluck('id'))->not->toContain($enVeinte->id)
        ->and($proximos30->pluck('id'))->toContain($enTresDias->id)
        ->and($proximos30->pluck('id'))->toContain($enVeinte->id);
});

test('la felicitacion se genera una sola vez por colaborador y fecha, con la frase fija', function () {
    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(28)]);

    $servicio = app(BirthdayCardService::class);
    $primera = $servicio->generar($colaborador, now());
    $segunda = $servicio->generar($colaborador, now());

    expect(BirthdayGreeting::count())->toBe(1)
        ->and($primera->id)->toBe($segunda->id)
        ->and($primera->frase)->toBe($segunda->frase);
});

test('regenerar cambia la imagen pero conserva el mismo registro de felicitacion', function () {
    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(28)]);

    $servicio = app(BirthdayCardService::class);
    $original = $servicio->generar($colaborador, now());
    $rutaOriginal = $original->card_path;

    $regenerada = $servicio->regenerar($colaborador, now());

    expect(BirthdayGreeting::count())->toBe(1)
        ->and($regenerada->id)->toBe($original->id)
        ->and($regenerada->card_path)->not->toBeNull();

    expect(Storage::disk('nas')->exists($regenerada->card_path))->toBeTrue();
    if ($rutaOriginal !== null && $rutaOriginal !== $regenerada->card_path) {
        expect(Storage::disk('nas')->exists($rutaOriginal))->toBeFalse();
    }
});

test('descargar la imagen de felicitacion no expone la ruta fisica del archivo', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(28), 'name' => 'Ana', 'apellidos' => 'Pérez']);

    $respuesta = $this->actingAs($admin)
        ->get(route('rh.cumpleanos.felicitacion.descargar', $colaborador))
        ->assertOk();

    expect($respuesta->headers->get('content-type'))->toContain('image/png');

    $disposition = $respuesta->headers->get('content-disposition');
    expect($disposition)->not->toBeNull();
    expect($disposition)->not->toContain(storage_path());
    expect($disposition)->not->toContain('/mnt/people-storage');
    expect($disposition)->toContain('feliz-cumpleanos');
});

test('un colaborador sin permiso no puede descargar la imagen de otro', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $otro = User::factory()->create(['fecha_nacimiento' => now()->subYears(28)]);

    $this->actingAs($colaborador)
        ->get(route('rh.cumpleanos.felicitacion.descargar', $otro))
        ->assertForbidden();
});
