<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Reclutamiento\CandidatoPresenter;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Candidatos consume la fotografía oficial del expediente (NAS) del
| colaborador vinculado: misma URL protegida que Expedientes
| (rh.expedientes.foto), solo dentro del alcance, nunca `foto_path`.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

function fcCandidato(array $estructura, ?Colaborador $colaborador = null): Candidato
{
    return Candidato::factory()->create([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'puesto_objetivo_id' => $estructura['puesto']->id,
        'estado' => $colaborador !== null ? EstadoCandidato::EnContratacion->value : EstadoCandidato::Recibidos->value,
        'colaborador_id' => $colaborador?->id,
    ]);
}

function fcColaborador(array $estructura, bool $conFoto): Colaborador
{
    $colaborador = Colaborador::factory()->create([
        'estatus' => 'en_incorporacion',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
    ]);

    if ($conFoto) {
        $ruta = sprintf('expedientes/%d/foto/perfil.jpg', $colaborador->id);
        Storage::disk(config('expedientes.disk'))->put($ruta, 'jpeg-falso');
        $colaborador->update(['foto_path' => $ruta]);
    }

    return $colaborador;
}

/**
 * @return array<string, mixed>|null
 */
function fcItemDelTablero($test, $usuario, Candidato $candidato): ?array
{
    $item = null;

    $test->actingAs($usuario)
        ->get(route('rh.candidatos.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$item, $candidato) {
            $item = collect($page->toArray()['props']['candidatos'])->firstWhere('id', $candidato->id);

            return $page;
        });

    return $item;
}

test('candidato sin colaborador vinculado: sin foto, el tablero y la ficha muestran iniciales', function () {
    $candidato = fcCandidato($this->estructura);

    expect(fcItemDelTablero($this, $this->rh, $candidato))->toHaveKey('foto_url', null);

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.show', $candidato))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('candidato.foto_url', null));
});

test('candidato con colaborador vinculado pero sin foto: iniciales', function () {
    $candidato = fcCandidato($this->estructura, fcColaborador($this->estructura, false));

    expect(fcItemDelTablero($this, $this->rh, $candidato))->toHaveKey('foto_url', null);

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.show', $candidato))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('candidato.foto_url', null));
});

test('candidato vinculado con foto: devuelve la URL autorizada del expediente y la imagen se sirve', function () {
    $colaborador = fcColaborador($this->estructura, true);
    $candidato = fcCandidato($this->estructura, $colaborador);
    $esperada = app(FotoColaboradorService::class)->url($colaborador);

    expect($esperada)->toStartWith(route('rh.expedientes.foto', $colaborador->id));
    expect(fcItemDelTablero($this, $this->rh, $candidato)['foto_url'] ?? null)->toBe($esperada);

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.show', $candidato))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('candidato.foto_url', $esperada));

    $this->actingAs($this->rh)->get($esperada)->assertOk();
});

test('quien ve candidatos pero no alcanza el expediente no recibe URL ni puede consultar la foto', function () {
    $colaborador = fcColaborador($this->estructura, true);
    $candidato = fcCandidato($this->estructura, $colaborador);
    $soloCandidatos = clUsuario('colaborador');
    $soloCandidatos->givePermissionTo(['candidatos.ver', 'candidatos.ver_todos']);

    expect(app(CandidatoPresenter::class)->fotoOficial($candidato, $soloCandidatos))->toBeNull()
        ->and(fcItemDelTablero($this, $soloCandidatos, $candidato))->toHaveKey('foto_url', null);

    // Aunque arme la URL a mano, la ruta protegida la rechaza.
    $this->actingAs($soloCandidatos)
        ->get(route('rh.expedientes.foto', $colaborador->id))
        ->assertForbidden();
});

test('nunca se expone foto_path (ruta física del NAS) en Candidatos', function () {
    $colaborador = fcColaborador($this->estructura, true);
    $candidato = fcCandidato($this->estructura, $colaborador);

    $indice = $this->actingAs($this->rh)->get(route('rh.candidatos.index'))->assertOk()->getContent();
    $ficha = $this->actingAs($this->rh)->get(route('rh.candidatos.show', $candidato))->assertOk()->getContent();

    foreach ([$indice, $ficha] as $html) {
        expect($html)->not->toContain('foto_path')
            ->and($html)->not->toContain(str_replace('/', '\\/', (string) $colaborador->foto_path))
            ->and($html)->not->toContain((string) $colaborador->foto_path);
    }
});
