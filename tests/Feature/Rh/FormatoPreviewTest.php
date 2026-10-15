<?php

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

test('el catálogo del módulo anterior se retiró: redirige a Documentos maestros', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    foreach (['rh.formatos.index', 'rh.formatos.catalogo.index', 'rh.plantillas.index', 'rh.formatos-oficiales.index', 'rh.formatos-oficiales.generados', 'rh.formatos-oficiales.variables'] as $ruta) {
        $this->actingAs($usuario)->get(route($ruta))->assertRedirect(route('rh.documentos-maestros.index'));
    }
});

test('el motor anterior de formatos ya no crea, edita, publica ni genera nada: responde 410', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');
    $mensaje = 'Este formato se retiró; usa Documentos del proceso.';

    $web = [
        ['post', '/rh/plantillas'],
        ['post', '/rh/plantillas/1'],
        ['put', '/rh/plantillas/1/variables'],
        ['delete', '/rh/plantillas/1'],
        ['get', '/rh/plantillas/exportar-excel'],
        ['post', '/rh/formatos'],
        ['post', '/rh/formatos/preview'],
        ['get', '/rh/formatos/1/descargar'],
        ['delete', '/rh/formatos/1'],
        ['post', '/rh/formatos-oficiales'],
        ['post', '/rh/formatos-oficiales/versiones/1/publicar'],
        ['post', '/rh/formatos-oficiales/1/versiones'],
        ['post', '/rh/formatos-oficiales/1/generar'],
        ['get', '/rh/formatos-oficiales/versiones/1/base'],
    ];

    foreach ($web as [$metodo, $url]) {
        $this->actingAs($usuario)->{$metodo.'Json'}($url)->assertStatus(410)->assertJsonPath('message', $mensaje);
    }

    Sanctum::actingAs($usuario);

    foreach ([['get', '/api/v1/rh/formatos'], ['post', '/api/v1/rh/formatos/1/generar'], ['get', '/api/v1/rh/formatos-oficiales'], ['post', '/api/v1/rh/formatos-oficiales'], ['post', '/api/v1/rh/formatos-oficiales/1/generar'], ['post', '/api/v1/rh/formatos-oficiales/versiones/1/publicar']] as [$metodo, $url]) {
        $this->{$metodo.'Json'}($url)->assertStatus(410)->assertJsonPath('message', $mensaje);
    }
});
