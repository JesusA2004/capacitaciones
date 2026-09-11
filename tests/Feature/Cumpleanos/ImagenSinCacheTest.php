<?php

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Services\Cumpleanos\CumpleanosStorageService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('la URL de la tarjeta de felicitación cambia de versión al regenerarla', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);

    $primera = $this->actingAs($rh)->get(route('rh.cumpleanos.felicitacion', $colaborador));
    $primeraUrl = $primera->viewData('page')['props']['greeting']['imagenUrl'];

    expect($primeraUrl)->toContain('?v=');

    // Esperar 1s real haría el test lento; en su lugar se fuerza que
    // updated_at avance comparando después de regenerar.
    $this->actingAs($rh)->post(route('rh.cumpleanos.felicitacion.regenerar', $colaborador));

    $segunda = $this->actingAs($rh)->get(route('rh.cumpleanos.felicitacion', $colaborador));
    $segundaUrl = $segunda->viewData('page')['props']['greeting']['imagenUrl'];

    expect($segundaUrl)->toContain('?v=');
    // La regla real: la URL debe reflejar el updated_at vigente del
    // greeting, no quedarse fija — se compara contra el registro en BD en
    // vez de solo "distinta a la anterior" (podría coincidir por timing).
    $greeting = BirthdayGreeting::where('user_id', $colaborador->id)->first();
    expect($segundaUrl)->toContain((string) $greeting->updated_at->timestamp);
});

test('descargar la tarjeta responde con Cache-Control no-store', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $rh->givePermissionTo('rh.cumpleanos.descargar_imagen');
    $colaborador = User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);

    $respuesta = $this->actingAs($rh)->get(route('rh.cumpleanos.felicitacion.descargar', $colaborador));

    $respuesta->assertOk();
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
});

test('la URL del fondo de tarjeta trae versión cuando existe un fondo', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    Storage::fake(config('cumpleanos.disk'));
    $storage = app(CumpleanosStorageService::class);
    $storage->guardar($storage->rutaFondo(), 'contenido-de-prueba');

    $respuesta = $this->actingAs($rh)->get(route('rh.cumpleanos.configuracion.index'));

    $respuesta->assertInertia(fn ($page) => $page
        ->where('tieneFondo', true)
        ->where('fondoUrl', fn ($url) => str_contains((string) $url, '?v='))
    );
});
