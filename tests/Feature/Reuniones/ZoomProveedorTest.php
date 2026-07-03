<?php

use App\Integrations\Reuniones\ZoomProveedor;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * No hay credenciales reales de Zoom en este entorno, asi que estas pruebas
 * usan Http::fake() para simular el intercambio Server-to-Server OAuth y la
 * creacion/cancelacion de la reunion, verificando que ZoomProveedor arme las
 * peticiones correctamente y guarde el enlace/ID devueltos por la API.
 */
beforeEach(function () {
    config([
        'services.zoom.habilitado' => true,
        'services.zoom.account_id' => 'cuenta-de-prueba',
        'services.zoom.client_id' => 'client-id-prueba',
        'services.zoom.client_secret' => 'client-secret-prueba',
        'services.zoom.host_email' => 'instructor@mrlana.test',
    ]);

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);

    $this->sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $leccion->id,
        'proveedor' => 'zoom',
        'creado_por' => User::factory()->create()->id,
    ]);
});

test('sin credenciales configuradas el proveedor no esta disponible y no llama a la API', function () {
    config(['services.zoom.habilitado' => false]);
    Http::fake();

    (new ZoomProveedor)->crearReunion($this->sesion);

    Http::assertNothingSent();
    expect($this->sesion->fresh()->enlace_reunion)->toBeNull();
});

test('crear la reunion obtiene un token y guarda el enlace devuelto por Zoom', function () {
    Http::fake([
        'https://zoom.us/oauth/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://api.zoom.us/v2/users/*/meetings' => Http::response(['id' => 123456789, 'join_url' => 'https://zoom.us/j/123456789']),
    ]);

    (new ZoomProveedor)->crearReunion($this->sesion);

    $this->sesion->refresh();
    expect($this->sesion->enlace_reunion)->toBe('https://zoom.us/j/123456789');
    expect($this->sesion->id_reunion_externa)->toBe('123456789');

    Http::assertSent(fn ($request) => $request->url() === 'https://zoom.us/oauth/token');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/instructor@mrlana.test/meetings'));
});

test('cancelar la reunion llama al endpoint de eliminacion de Zoom', function () {
    $this->sesion->update(['id_reunion_externa' => '123456789']);

    Http::fake([
        'https://zoom.us/oauth/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://api.zoom.us/v2/meetings/*' => Http::response([], 204),
    ]);

    (new ZoomProveedor)->cancelarReunion($this->sesion);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/meetings/123456789'));
});
