<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use League\Flysystem\UnableToRetrieveMetadata;

/*
 * Producción mostró la pantalla completa de excepción de Laravel (queries,
 * paths, headers, cookies). Con APP_DEBUG=false eso nunca debe ocurrir.
 */

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/_prueba/explota', fn () => throw new RuntimeException('SECRETO-INTERNO-xyz /var/www/ruta'));
    Route::middleware('web')->get('/_prueba/sin-archivo', fn () => throw UnableToRetrieveMetadata::fileSize('expedientes/1/no-existe.pdf'));
    Route::middleware('api')->get('/api/_prueba/sin-archivo', fn () => throw UnableToRetrieveMetadata::fileSize('expedientes/1/no-existe.pdf'));
});

test('un 500 muestra la página corporativa sin trazas ni mensaje interno', function () {
    $respuesta = $this->get('/_prueba/explota');

    $respuesta->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500))
        ->assertDontSee('SECRETO-INTERNO-xyz')
        ->assertDontSee('/var/www/ruta')
        ->assertDontSee('Illuminate\\', false)
        ->assertDontSee('vendor/laravel', false);
});

test('un 404 muestra la página corporativa', function () {
    $this->get('/esta-ruta-no-existe')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
});

test('un 403 muestra la página corporativa', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('administracion.roles.index'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
});

test('la API sigue respondiendo JSON y sin detalles internos', function () {
    $this->getJson('/api/v1/ruta-que-no-existe')->assertNotFound()->assertJsonMissingPath('trace');
});

test('un archivo faltante al descargar nunca es 500: web regresa con aviso, API responde 404 JSON', function () {
    $this->from('/dashboard')->get('/_prueba/sin-archivo')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('toast.message', 'El archivo fuente de este documento ya no está disponible.');

    $this->getJson('/api/_prueba/sin-archivo')
        ->assertNotFound()
        ->assertJsonPath('message', 'El archivo fuente de este documento ya no está disponible.');
});

test('con APP_DEBUG=true (desarrollo) no se reemplaza la respuesta de error', function () {
    config(['app.debug' => true]);

    $respuesta = $this->get('/_prueba/explota')->assertStatus(500);

    expect($respuesta->headers->get('X-Inertia'))->toBeNull();
});

test('people:diagnostico advierte APP_DEBUG=true en producción', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['app.debug' => true]);

    $this->artisan('people:diagnostico')
        ->expectsOutputToContain('APP_DEBUG=true en producción')
        ->assertExitCode(1);
});
