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

test('un 500 en una acción Inertia regresa a la pantalla con un aviso y una referencia, sin detalles internos', function () {
    Route::middleware('web')->post('/_prueba/accion-explota', fn () => throw new RuntimeException('SECRETO-INTERNO-xyz'));

    $respuesta = $this->from('/dashboard')
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/_prueba/accion-explota');

    $respuesta->assertRedirect('/dashboard');
    expect(session('toast.message'))->toContain('Referencia:')->not->toContain('SECRETO');
});

test('un 403 en una acción Inertia regresa con el motivo humano', function () {
    Route::middleware('web')->post('/_prueba/accion-prohibida', fn () => abort(403, 'Solo RH puede autorizar la contratación.'));

    $this->from('/dashboard')
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/_prueba/accion-prohibida')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('toast.message', 'Solo RH puede autorizar la contratación.');
});

test('la página de un 500 incluye un identificador para Sistemas', function () {
    $this->get('/_prueba/explota')
        ->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('mensaje', fn ($m) => str_contains((string) $m, 'Referencia:')));
});
