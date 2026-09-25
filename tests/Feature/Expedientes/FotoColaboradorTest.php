<?php

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
