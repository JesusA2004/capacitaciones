<?php

use App\Enums\Genero;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

/**
 * @param  array<string, mixed>  $ficha
 * @return array<string, array<string, mixed>>
 */
function faCampos(array $ficha): array
{
    return collect($ficha['campos'])->keyBy('clave')->all();
}

test('RH obtiene la ficha de alta del colaborador con la fecha en ambos formatos', function () {
    $sucursal = Sucursal::factory()->create(['nombre' => 'Cuernavaca Centro']);
    $puesto = Puesto::factory()->create(['nombre' => 'Asesor de ventas']);
    $colaborador = Colaborador::factory()->create([
        'name' => 'Ana María',
        'apellidos' => 'López Ruiz',
        'fecha_nacimiento' => '1990-08-15',
        'fecha_ingreso' => '2026-03-02',
        'curp' => 'LORA900815MMSPZN09',
        'telefono' => '7771234567',
        'genero' => Genero::Femenino,
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
    ]);
    $rh = clUsuario('rh_admin');

    $respuesta = $this->actingAs($rh)
        ->getJson(route('rh.fichas-alta.colaborador', $colaborador))
        ->assertOk()
        ->assertJsonPath('tipo', 'colaborador')
        ->assertJsonPath('titulo', 'Ana María López Ruiz');

    $campos = faCampos($respuesta->json());

    expect($campos['fecha_nacimiento']['valor'])->toBe('15 de agosto de 1990 · 15/08/1990')
        ->and($campos['fecha_nacimiento']['copiar'])->toBe('15/08/1990')
        ->and($campos['fecha_ingreso']['valor'])->toBe('2 de marzo de 2026 · 02/03/2026')
        ->and($campos['curp']['valor'])->toBe('LORA900815MMSPZN09')
        ->and($campos['telefono']['valor'])->toBe('7771234567')
        ->and($campos['genero']['valor'])->toBe('Femenino')
        ->and($campos['sucursal']['valor'])->toBe('Cuernavaca Centro')
        ->and($campos['puesto']['valor'])->toBe('Asesor de ventas')
        ->and($campos)->not->toHaveKey('sueldo_mensual');
});

test('los datos vacíos de la ficha se muestran como «Sin capturar» y no se copian', function () {
    $colaborador = Colaborador::factory()->create([
        'curp' => null,
        'rfc' => null,
        'nss' => null,
        'fecha_nacimiento' => null,
        'telefono' => null,
    ]);
    $rh = clUsuario('rh_admin');

    $campos = faCampos($this->actingAs($rh)
        ->getJson(route('rh.fichas-alta.colaborador', $colaborador))
        ->assertOk()
        ->json());

    foreach (['curp', 'rfc', 'nss', 'fecha_nacimiento', 'telefono'] as $clave) {
        expect($campos[$clave]['valor'])->toBe('Sin capturar')
            ->and($campos[$clave]['capturado'])->toBeFalse()
            ->and($campos[$clave]['copiar'])->toBe('');
    }
});

test('un gerente no obtiene la ficha de un colaborador de otra sucursal', function () {
    $propia = Sucursal::factory()->create();
    $ajena = Sucursal::factory()->create();
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $propia->id]);

    $colaboradorPropio = Colaborador::factory()->create(['sucursal_principal_id' => $propia->id]);
    $colaboradorAjeno = Colaborador::factory()->create(['sucursal_principal_id' => $ajena->id]);

    $this->actingAs($gerente)->getJson(route('rh.fichas-alta.colaborador', $colaboradorPropio))->assertOk();
    $this->actingAs($gerente)->getJson(route('rh.fichas-alta.colaborador', $colaboradorAjeno))->assertForbidden();
});

test('un colaborador sin permisos no obtiene fichas ajenas ni de candidatos', function () {
    $colaborador = clUsuario('colaborador');
    $otro = Colaborador::factory()->create();
    $candidato = Candidato::factory()->create();

    $this->actingAs($colaborador)->getJson(route('rh.fichas-alta.colaborador', $otro))->assertForbidden();
    $this->actingAs($colaborador)->getJson(route('rh.fichas-alta.candidato', $candidato))->assertForbidden();
});

test('la ficha de un candidato sin colaborador usa sus datos y marca lo que falta', function () {
    $candidato = Candidato::factory()->create([
        'nombre' => 'Luis',
        'apellidos' => 'Pérez',
        'telefono' => '5550001111',
        'sucursal_id' => null,
    ]);
    $rh = clUsuario('rh_admin');

    $respuesta = $this->actingAs($rh)
        ->getJson(route('rh.fichas-alta.candidato', $candidato))
        ->assertOk()
        ->assertJsonPath('tipo', 'candidato')
        ->assertJsonPath('titulo', 'Luis Pérez');

    $campos = faCampos($respuesta->json());

    expect($campos['telefono']['valor'])->toBe('5550001111')
        ->and($campos['curp']['valor'])->toBe('Sin capturar')
        ->and($campos['fecha_nacimiento']['valor'])->toBe('Sin capturar')
        ->and($campos['sucursal']['valor'])->toBe('Sin capturar');
});

test('la ficha de un candidato contratado trae los datos del colaborador si se alcanza su expediente', function () {
    $colaborador = Colaborador::factory()->create(['curp' => 'PEXL900101HDFRXS01', 'fecha_nacimiento' => '1990-01-01']);
    $candidato = Candidato::factory()->create(['colaborador_id' => $colaborador->id, 'sucursal_id' => null]);
    $rh = clUsuario('rh_admin');

    $campos = faCampos($this->actingAs($rh)
        ->getJson(route('rh.fichas-alta.candidato', $candidato))
        ->assertOk()
        ->assertJsonPath('tipo', 'colaborador')
        ->json());

    expect($campos['curp']['valor'])->toBe('PEXL900101HDFRXS01')
        ->and($campos['fecha_nacimiento']['valor'])->toBe('1 de enero de 1990 · 01/01/1990');

    // Quien ve candidatos pero no alcanza el expediente: solo lo del candidato.
    $soloCandidatos = clUsuario('colaborador');
    $soloCandidatos->givePermissionTo(['candidatos.ver', 'candidatos.ver_todos']);

    $campos = faCampos($this->actingAs($soloCandidatos)
        ->getJson(route('rh.fichas-alta.candidato', $candidato))
        ->assertOk()
        ->assertJsonPath('tipo', 'candidato')
        ->json());

    expect($campos['curp']['valor'])->toBe('Sin capturar');
});

test('un usuario con candidatos por sucursal no obtiene la ficha de un candidato de otra sucursal', function () {
    $propia = Sucursal::factory()->create();
    $ajena = Sucursal::factory()->create();
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => $propia->id]);
    $usuario->givePermissionTo(['candidatos.ver', 'candidatos.ver_sucursal']);

    $candidato = Candidato::factory()->create(['sucursal_id' => $ajena->id]);

    $this->actingAs($usuario)->getJson(route('rh.fichas-alta.candidato', $candidato))->assertForbidden();
});

test('usuario sin sesión no obtiene fichas', function () {
    $colaborador = Colaborador::factory()->create();

    $this->getJson(route('rh.fichas-alta.colaborador', $colaborador))->assertUnauthorized();
});
