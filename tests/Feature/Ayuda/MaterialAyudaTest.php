<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Material de apoyo del centro de Ayuda: `ayuda:publicar-material` copia
 * docs/ayuda al disco privado (idempotente) y /ayuda/material solo muestra
 * y sirve lo publicado. Usa un origen temporal con archivos pequeños.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->origen = storage_path('framework/testing/ayuda-origen');
    File::ensureDirectoryExists($this->origen);
    config(['ayuda_material.origen' => $this->origen]);
});

afterEach(function () {
    File::deleteDirectory($this->origen);
});

function crearOrigenAyuda(string $carpeta): void
{
    foreach (config('ayuda_material.materiales') as $m) {
        File::put($carpeta.'/'.$m['archivo'], 'contenido '.$m['slug']);
    }
}

test('el comando falla y avisa cuando falta un archivo en docs/ayuda', function () {
    crearOrigenAyuda($this->origen);
    File::delete($this->origen.'/Infografia_Gerentes_Antes_y_Ahora.pdf');

    $this->artisan('ayuda:publicar-material')
        ->expectsOutputToContain('Falta Infografia_Gerentes_Antes_y_Ahora.pdf')
        ->assertFailed();
});

test('publica el material, no repite lo que no cambió y la pantalla lo sirve', function () {
    crearOrigenAyuda($this->origen);

    $this->artisan('ayuda:publicar-material')->assertSuccessful();
    Storage::disk('local')->assertExists('ayuda/4_Manual_de_Operacion.mp4');

    $this->artisan('ayuda:publicar-material')
        ->expectsOutputToContain('Sin cambios')
        ->assertSuccessful();

    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get('/ayuda/material')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Ayuda/Material')->has('materiales', 9));

    $this->actingAs($usuario)
        ->get('/ayuda/material/archivo/manual-operacion')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $descarga = $this->actingAs($usuario)->get('/ayuda/material/archivo/manual-operacion?descargar=1');
    $descarga->assertOk();
    expect($descarga->headers->get('Content-Disposition'))->toContain('attachment');

    $this->actingAs($usuario)->get('/ayuda/material/archivo/no-existe')->assertNotFound();
});

test('sin publicar la pantalla queda vacía y el material exige sesión', function () {
    $this->get('/ayuda/material')->assertRedirect(route('login'));
    $this->get('/ayuda/material/archivo/manual-operacion')->assertRedirect(route('login'));

    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get('/ayuda/material')
        ->assertInertia(fn ($page) => $page->has('materiales', 0));
    $this->actingAs($usuario)->get('/ayuda/material/archivo/manual-operacion')->assertNotFound();
});
