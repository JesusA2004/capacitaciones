<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Colaboradores\FotoColaboradorService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| El directorio muestra la foto oficial del expediente (NAS) con la misma URL
| protegida de Expedientes (rh.expedientes.foto), solo a quien alcanza ese
| expediente; a los demás les llegan iniciales. Nunca viaja `foto_path`.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true, 'es_corporativo' => false]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'grupo_documental' => 'gerente', 'nivel_jerarquico' => 4, 'activo' => true]);
});

function dfPersona(Sucursal $sucursal, Puesto $puesto, string $nombre, bool $conFoto): Colaborador
{
    $colaborador = Colaborador::factory()->create(['name' => $nombre, 'apellidos' => 'Prueba', 'puesto_id' => $puesto->id, 'sucursal_principal_id' => $sucursal->id, 'estatus' => 'activo']);

    if ($conFoto) {
        $ruta = sprintf('expedientes/%d/foto/perfil.jpg', $colaborador->id);
        Storage::disk(config('expedientes.disk'))->put($ruta, 'jpeg-falso');
        $colaborador->update(['foto_path' => $ruta]);
    }

    return $colaborador;
}

/**
 * @return array<string, array<string, mixed>>
 */
function dfFilas($test, $usuario): array
{
    $filas = [];

    $test->actingAs($usuario)->get(route('directorio.index'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$filas) {
            $filas = collect($page->toArray()['props']['colaboradores'])->keyBy('nombre')->all();

            return $page;
        });

    return $filas;
}

test('RH ve la foto oficial de quien la tiene e iniciales de quien no', function () {
    $conFoto = dfPersona($this->tula, $this->gerente, 'María', true);
    dfPersona($this->tula, $this->gerente, 'Rosa', false);

    $filas = dfFilas($this, clUsuario('rh_admin'));

    expect($filas['María Prueba']['foto_url'])->toBe(app(FotoColaboradorService::class)->url($conFoto))
        ->and($filas['María Prueba']['foto_url'])->toStartWith(route('rh.expedientes.foto', $conFoto->id))
        ->and($filas['Rosa Prueba']['foto_url'])->toBeNull();
});

test('un colaborador ve a sus compañeros en el directorio pero sin la foto de su expediente', function () {
    $companera = dfPersona($this->tula, $this->gerente, 'María', true);
    $operativo = clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]);

    $filas = dfFilas($this, $operativo);

    expect($filas)->toHaveKey('María Prueba')
        ->and($filas['María Prueba']['foto_url'])->toBeNull();

    // Aunque arme la URL a mano, la ruta protegida la rechaza.
    $this->actingAs($operativo)->get(route('rh.expedientes.foto', $companera->id))->assertForbidden();
});

test('el directorio nunca expone foto_path (ruta física del NAS)', function () {
    $conFoto = dfPersona($this->tula, $this->gerente, 'María', true);

    $html = $this->actingAs(clUsuario('rh_admin'))->get(route('directorio.index'))->assertOk()->getContent();

    expect($html)->not->toContain('foto_path')
        ->and($html)->not->toContain((string) $conFoto->foto_path)
        ->and($html)->not->toContain(str_replace('/', '\\/', (string) $conFoto->foto_path));
});
