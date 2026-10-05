<?php

use App\Enums\EstadoCambioFoto;
use App\Models\CambioFotoPerfil;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Colaboradores\FotoColaboradorService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
});

test('el colaborador sube su propia foto y queda normalizada como miniatura cuadrada', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $colaborador = $usuario->colaborador;
    expect($colaborador)->not->toBeNull();

    // Foto vertical de celular: debe recortarse a cuadrado 800x800.
    $this->actingAs($usuario)
        ->post(route('portal.foto'), ['foto' => UploadedFile::fake()->image('selfie.jpg', 900, 1600)])
        ->assertRedirect();

    $colaborador->refresh();
    expect($colaborador->foto_path)->not->toBeNull();

    $contenido = Storage::disk(config('expedientes.disk'))->get($colaborador->foto_path);
    [$ancho, $alto] = getimagesizefromstring((string) $contenido);

    expect($ancho)->toBe(800)->and($alto)->toBe(800);
});

test('cambiar la foto conserva la anterior y la URL cambia de version para no mostrar la vieja desde cache', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = Colaborador::factory()->create();

    $this->actingAs($rh)
        ->post(route('rh.expedientes.foto.store', $colaborador), ['foto' => UploadedFile::fake()->image('a.png', 600, 600)])
        ->assertRedirect();
    $primera = $colaborador->refresh()->foto_path;
    $urlPrimera = app(FotoColaboradorService::class)->url($colaborador);

    $this->travel(2)->seconds();

    $this->actingAs($rh)
        ->post(route('rh.expedientes.foto.store', $colaborador), ['foto' => UploadedFile::fake()->image('b.jpg', 1200, 800)])
        ->assertRedirect();
    $colaborador->refresh();

    expect($colaborador->foto_path)->not->toBe($primera)
        ->and(Storage::disk(config('expedientes.disk'))->exists($primera))->toBeTrue()
        ->and(app(FotoColaboradorService::class)->url($colaborador))->not->toBe($urlPrimera);
});

test('un colaborador no puede cambiar la foto de otro', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $otro = Colaborador::factory()->create();

    $this->actingAs($usuario)
        ->post(route('rh.expedientes.foto.store', $otro), ['foto' => UploadedFile::fake()->image('x.jpg', 400, 400)])
        ->assertForbidden();

    expect($otro->refresh()->foto_path)->toBeNull();
});

test('un archivo que no es imagen se rechaza con mensaje en espanol', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->post(route('portal.foto'), ['foto' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors(['foto' => 'El archivo debe ser una imagen.']);
});

test('la app movil sube la foto con token y recibe la nueva url', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $token = $usuario->createToken('test')->plainTextToken;

    $respuesta = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('selfie.jpg', 700, 900)], ['Accept' => 'application/json'])
        ->assertOk();

    expect($respuesta->json('foto_url'))->toContain('/api/v1/colaborador/foto?v=')
        ->and($usuario->colaborador->refresh()->foto_path)->not->toBeNull();
});

/*
 * Primera foto directa; cambios posteriores pasan por aprobación de RH
 * (FotoColaboradorService::subirPropia / aprobar / rechazar).
 */
function fcColaboradorConToken(): array
{
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    return [$usuario, ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken, 'Accept' => 'application/json']];
}

test('la primera foto queda oficial al instante; la segunda queda pendiente y la oficial no cambia', function () {
    [$usuario, $headers] = fcColaboradorConToken();

    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('a.jpg', 600, 600)])
        ->assertOk()->assertJsonPath('resultado', 'oficial')->assertJsonPath('foto.estado', 'oficial');
    $oficial = $usuario->colaborador->refresh()->foto_path;

    $this->travel(2)->seconds();

    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('b.jpg', 600, 600)])
        ->assertStatus(202)->assertJsonPath('resultado', 'pendiente')->assertJsonPath('foto.estado', 'cambio_pendiente');

    expect($usuario->colaborador->refresh()->foto_path)->toBe($oficial)
        ->and(CambioFotoPerfil::query()->where('estado', 'pendiente')->count())->toBe(1);

    // No se permiten dos solicitudes pendientes.
    $this->travel(2)->seconds();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('c.jpg', 600, 600)])
        ->assertStatus(422)->assertJsonValidationErrors('foto');

    // La propuesta la ve su dueño, no la expone como oficial.
    $this->withHeaders($headers)->get('/api/v1/colaborador/foto/propuesta')->assertOk();
    $this->withHeaders($headers)->get('/api/v1/colaborador/foto/estado')->assertOk()->assertJsonPath('pendiente.id', CambioFotoPerfil::query()->value('id'));
});

test('RH aprueba: la propuesta pasa a oficial, la anterior queda en el historial y se avisa al colaborador', function () {
    [$usuario, $headers] = fcColaboradorConToken();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('a.jpg', 600, 600)]);
    $anterior = $usuario->colaborador->refresh()->foto_path;
    $this->travel(2)->seconds();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('b.jpg', 600, 600)]);
    $cambio = CambioFotoPerfil::query()->firstOrFail();

    $rh = clUsuario('rh_admin');
    $this->actingAs($rh)->get(route('rh.cambios-foto.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/CambiosFoto/Index')->has('cambios', 1));
    $this->actingAs($rh)->get(route('rh.cambios-foto.propuesta', $cambio))->assertOk();
    $this->actingAs($rh)->post(route('rh.cambios-foto.aprobar', $cambio))->assertRedirect();

    $cambio->refresh();
    expect($cambio->estado)->toBe(EstadoCambioFoto::Aprobado)
        ->and($cambio->foto_anterior_path)->toBe($anterior)
        ->and($usuario->colaborador->refresh()->foto_path)->toBe($cambio->foto_path)
        ->and(Storage::disk(config('expedientes.disk'))->exists($anterior))->toBeTrue()
        ->and($usuario->notifications()->where('data->tipo', 'foto_perfil')->count())->toBe(1);

    // Ya resuelto: no se puede aprobar dos veces.
    $this->actingAs($rh)->post(route('rh.cambios-foto.aprobar', $cambio))->assertInvalid(['cambio']);
});

test('RH rechaza con motivo: la foto actual se conserva y el colaborador ve el motivo', function () {
    [$usuario, $headers] = fcColaboradorConToken();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('a.jpg', 600, 600)]);
    $oficial = $usuario->colaborador->refresh()->foto_path;
    $this->travel(2)->seconds();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('b.jpg', 600, 600)]);
    $cambio = CambioFotoPerfil::query()->firstOrFail();

    $rh = clUsuario('rh_admin');
    $rhToken = $rh->createToken('rh')->plainTextToken;
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$rhToken}", 'Accept' => 'application/json'])
        ->post("/api/v1/rh/cambios-foto/{$cambio->id}/rechazar", ['motivo' => 'No se ve el rostro completo'])
        ->assertOk();
    app('auth')->forgetGuards();

    expect($usuario->colaborador->refresh()->foto_path)->toBe($oficial);
    app('auth')->forgetGuards();
    $this->withHeaders($headers)->get('/api/v1/colaborador/foto/estado')->assertOk()
        ->assertJsonPath('estado', 'oficial')
        ->assertJsonPath('ultimo_cambio.estado', 'rechazado')
        ->assertJsonPath('ultimo_cambio.motivo_rechazo', 'No se ve el rostro completo');

    // Ya puede pedir otro cambio.
    $this->travel(2)->seconds();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('c.jpg', 600, 600)])->assertStatus(202);
});

test('un colaborador sin permiso no puede revisar cambios de foto ni ver propuestas ajenas', function () {
    [$usuario, $headers] = fcColaboradorConToken();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('a.jpg', 600, 600)]);
    $this->travel(2)->seconds();
    $this->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('b.jpg', 600, 600)]);
    $cambio = CambioFotoPerfil::query()->firstOrFail();

    [, $otroHeaders] = fcColaboradorConToken();
    app('auth')->forgetGuards();
    $this->withHeaders($otroHeaders)->post("/api/v1/rh/cambios-foto/{$cambio->id}/aprobar")->assertForbidden();
    $this->withHeaders($otroHeaders)->get("/api/v1/rh/cambios-foto/{$cambio->id}/propuesta")->assertForbidden();
    // Ni el propio colaborador aprueba su foto.
    app('auth')->forgetGuards();
    $this->withHeaders($headers)->post("/api/v1/rh/cambios-foto/{$cambio->id}/aprobar")->assertForbidden();

    expect($cambio->refresh()->estado)->toBe(EstadoCambioFoto::Pendiente);
});

test('un archivo que no es imagen aunque se llame .jpg se rechaza', function () {
    [, $headers] = fcColaboradorConToken();

    $this->withHeaders($headers)
        ->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->createWithContent('foto.jpg', '%PDF-1.4 no soy imagen')])
        ->assertStatus(422)->assertJsonValidationErrors('foto');
});
