<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Mobile\MobileBootstrapService;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Sin autoservicio documental (features.documentos_laborales_colaborador)
|--------------------------------------------------------------------------
| El colaborador recibe su copia FÍSICA firmada: con la bandera apagada (por
| defecto) no ve, no abre ni descarga sus documentos generados ni sus
| contratos, ni en la web ni en la app. Única excepción: lo que tiene
| pendiente de SU firma digital. RH no cambia.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['features.documentos_laborales_colaborador' => false]);

    $this->usuario = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $this->usuario->assignRole('colaborador');

    $this->contrato = GeneratedDocument::factory()->create([
        'colaborador_id' => $this->usuario->colaborador_id,
        'categoria' => CategoriaDocumento::Contratos->value,
        'estado_flujo' => EstadoFlujoDocumento::Archivado->value,
    ]);
});

test('el colaborador no puede ver ni descargar su contrato', function () {
    expect($this->usuario->can('ver', $this->contrato))->toBeFalse()
        ->and($this->usuario->can('descargar', $this->contrato))->toBeFalse();
});

test('la web no muestra el contrato: 404 en detalle, visor, descarga y «Mis contratos»', function () {
    $this->actingAs($this->usuario);

    $this->get(route('portal.contratos'))->assertNotFound();
    $this->get(route('portal.documentos-laborales.show', $this->contrato->id))->assertNotFound();
    $this->get(route('portal.documentos-laborales.ver', $this->contrato->id))->assertNotFound();
    $this->get(route('portal.documentos-laborales.descargar', $this->contrato->id))->assertNotFound();

    $this->get(route('portal.documentos-laborales.index'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('documentos.data', []));
});

test('la web comparte la bandera apagada para ocultar los accesos del menú', function () {
    $this->actingAs($this->usuario)
        ->get(route('portal.index'))
        ->assertInertia(fn ($pagina) => $pagina->where('features.documentos_laborales_colaborador', false));
});

test('la app no recibe el contrato ni la lista de contratos, y el bootstrap oculta los módulos', function () {
    Sanctum::actingAs($this->usuario);

    $this->getJson(route('api.v1.colaborador.documentos-laborales.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('api.v1.colaborador.contratos'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('api.v1.colaborador.documentos-laborales.show', $this->contrato->id))->assertForbidden();
    $this->get(route('api.v1.colaborador.documentos-laborales.descargar', $this->contrato->id), ['Accept' => 'application/json'])->assertForbidden();

    $bootstrap = app(MobileBootstrapService::class)->bootstrap($this->usuario);

    expect($bootstrap['features']['documentos_laborales'])->toBeFalse()
        ->and($bootstrap['features']['contratos'])->toBeFalse();
});

test('lo pendiente de su firma digital sí lo abre, para poder firmarlo', function () {
    $porFirmar = GeneratedDocument::factory()->create([
        'colaborador_id' => $this->usuario->colaborador_id,
        'estado_flujo' => EstadoFlujoDocumento::PendienteFirmaColaborador->value,
    ]);

    expect($this->usuario->can('ver', $porFirmar))->toBeTrue()
        ->and($this->usuario->can('firmar', $porFirmar))->toBeTrue();

    $this->actingAs($this->usuario)
        ->get(route('portal.documentos-laborales.show', $porFirmar->id))
        ->assertOk();
});

test('RH sigue viendo el contrato del colaborador', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    expect($rh->can('ver', $this->contrato))->toBeTrue();
});

test('con la bandera encendida el colaborador vuelve a ver su contrato', function () {
    config(['features.documentos_laborales_colaborador' => true]);

    expect($this->usuario->can('ver', $this->contrato))->toBeTrue();

    $this->actingAs($this->usuario)->get(route('portal.contratos'))->assertOk();
});
