<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Buscador único de colaboradores (BuscadorColaboradoresService): cada
| palabra AND sobre nombre, apellidos, número, correo, puesto, sucursal
| (nombre o clave) y departamento; sin acentos; nunca ensancha el alcance.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->estructura = clEstructura(); // sucursal «Cuernavaca»
    $this->cuernavaca = $this->estructura['sucursal'];
    $this->cuernavaca->update(['clave' => 'CVA']);
    $this->orizaba = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id, 'nombre' => 'Orizaba', 'clave' => 'ORZ']);
    $this->ventas = Puesto::factory()->create(['nombre' => 'Asesor de Ventas']);
    $this->cobranza = Departamento::factory()->create(['nombre' => 'Cobranza']);

    $base = ['estatus' => EstadoUsuario::Activo, 'puesto_id' => $this->estructura['puesto']->id, 'departamento_id' => $this->estructura['departamento']->id];

    $this->juanito = Colaborador::factory()->create([...$base, 'name' => 'Juanito', 'apellidos' => 'Pérez López', 'numero_empleado' => '1023', 'sucursal_principal_id' => $this->cuernavaca->id]);
    $this->jose = Colaborador::factory()->create([...$base, 'name' => 'José', 'apellidos' => 'Núñez', 'numero_empleado' => '2001', 'sucursal_principal_id' => $this->cuernavaca->id, 'puesto_id' => $this->ventas->id]);
    $this->juanOrizaba = Colaborador::factory()->create([...$base, 'name' => 'Juan', 'apellidos' => 'Ramírez', 'numero_empleado' => '3001', 'sucursal_principal_id' => $this->orizaba->id, 'departamento_id' => $this->cobranza->id]);
});

function bcIds(?string $termino): array
{
    return Colaborador::query()->buscar($termino)->pluck('id')->sort()->values()->all();
}

test('buscar el nombre de una sucursal trae a su gente (y su clave también)', function () {
    expect(bcIds('Cuernavaca'))->toBe(collect([$this->juanito->id, $this->jose->id])->sort()->values()->all())
        ->and(bcIds('cuerna'))->toContain($this->juanito->id)->not->toContain($this->juanOrizaba->id)
        ->and(bcIds('ORZ'))->toBe([$this->juanOrizaba->id]);
});

test('varias palabras se combinan con AND entre campos distintos', function () {
    expect(bcIds('juan cuernavaca'))->toBe([$this->juanito->id])
        ->and(bcIds('juan orizaba'))->toBe([$this->juanOrizaba->id])
        ->and(bcIds('perez 1023'))->toBe([$this->juanito->id])
        ->and(bcIds('perez orizaba'))->toBe([]);
});

test('ignora acentos y mayúsculas, en el término y en los datos', function () {
    expect(bcIds('jose nunez'))->toBe([$this->jose->id])
        ->and(bcIds('JOSÉ'))->toBe([$this->jose->id])
        ->and(bcIds('ramirez'))->toBe([$this->juanOrizaba->id]);
});

test('busca también por puesto y por departamento', function () {
    expect(bcIds('ventas'))->toBe([$this->jose->id])
        ->and(bcIds('cobranza'))->toBe([$this->juanOrizaba->id]);
});

test('un término vacío no filtra y los comodines de LIKE no se usan como comodín', function () {
    $total = Colaborador::query()->count();

    expect(Colaborador::query()->buscar('')->count())->toBe($total)
        ->and(Colaborador::query()->buscar('   ')->count())->toBe($total)
        ->and(bcIds('%'))->toHaveCount($total)
        ->and(app(BuscadorColaboradoresService::class)->palabras('  Juan_Pérez  cuerna '))->toBe(['juan', 'perez', 'cuerna']);
});

test('respeta el alcance: un gerente que busca «Orizaba» no ve a la gente de otra sucursal', function () {
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->cuernavaca->id]);

    $ids = fn (string $q) => collect($this->actingAs($gerente)->getJson(route('rh.bajas.colaboradores', ['q' => $q]))->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('Cuernavaca'))->toContain($this->juanito->id)->toContain($this->jose->id)->not->toContain($this->juanOrizaba->id)
        ->and($ids('Orizaba'))->toBe([])
        ->and($ids('juan'))->toContain($this->juanito->id)->not->toContain($this->juanOrizaba->id);
});

test('el buscador de testigos de actas encuentra por sucursal sin salirse del alcance', function () {
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->cuernavaca->id]);

    $nombres = collect($this->actingAs($gerente)->getJson(route('rh.actas.testigos', ['q' => 'cuernavaca jose']))->assertOk()->json('data'))->pluck('id')->all();

    expect($nombres)->toBe([$this->jose->id]);

    $ajenos = collect($this->actingAs($gerente)->getJson(route('rh.actas.testigos', ['q' => 'orizaba']))->assertOk()->json('data'))->pluck('id')->all();

    expect($ajenos)->not->toContain($this->juanOrizaba->id);
});
