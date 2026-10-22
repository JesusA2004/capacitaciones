<?php

use App\Models\Aviso;
use App\Models\Puesto;
use App\Models\User;
use App\Support\Paginacion;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Selector «Mostrar [N] por página» (10 · 20 · 30 · 40 · 50 · Todos):
 * App\Support\Paginacion lo interpreta igual en todos los listados.
 */
function paginacionConQuery(array $query): void
{
    app()->instance('request', Request::create('/listado', 'GET', $query));
}

test('acepta las opciones del catálogo en por_pagina', function (int $valor) {
    paginacionConQuery(['por_pagina' => (string) $valor]);

    expect(Paginacion::porPagina(15))->toBe($valor);
})->with([10, 20, 30, 40, 50]);

test('«todos» trae todo pero acotado al tope configurado', function () {
    config(['paginacion.todos_maximo' => 300]);

    paginacionConQuery(['por_pagina' => 'todos']);
    expect(Paginacion::porPagina())->toBe(300);

    paginacionConQuery(['por_pagina' => 'TODOS']);
    expect(Paginacion::porPagina())->toBe(300);

    expect(Paginacion::maximoTodos())->toBe(300);
});

test('un valor inválido en por_pagina cae al default del listado', function (mixed $valor) {
    paginacionConQuery(['por_pagina' => $valor]);

    expect(Paginacion::porPagina(24))->toBe(24);
})->with(['15', '0', '-10', '100', 'abc', '9999']);

test('sin parámetro usa el default del listado', function () {
    paginacionConQuery([]);

    expect(Paginacion::porPagina())->toBe(Paginacion::POR_DEFECTO)
        ->and(Paginacion::porPagina(50))->toBe(50);
});

test('per_page (legado: app móvil y URLs viejas) sigue aceptando cualquier entero hasta el tope', function () {
    config(['paginacion.todos_maximo' => 2000]);

    paginacionConQuery(['per_page' => '15']);
    expect(Paginacion::porPagina())->toBe(15);

    paginacionConQuery(['per_page' => '5000']);
    expect(Paginacion::porPagina())->toBe(2000);

    paginacionConQuery(['per_page' => 'nada']);
    expect(Paginacion::porPagina(30))->toBe(30);
});

test('por_pagina tiene prioridad sobre per_page y el valor explícito del Service sobre ambos', function () {
    paginacionConQuery(['por_pagina' => '40', 'per_page' => '15']);
    expect(Paginacion::porPagina())->toBe(40);

    expect(Paginacion::porPagina(20, 12))->toBe(12)
        ->and(Paginacion::porPagina(20, 'todos'))->toBe(Paginacion::maximoTodos())
        ->and(Paginacion::porPagina(20, ''))->toBe(40);
});

test('el catálogo de puestos respeta por_pagina', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $usuario = User::factory()->create();
    $usuario->assignRole('administrador_capacitacion');
    Puesto::factory()->count(25)->create();
    $total = Puesto::query()->count();

    $this->actingAs($usuario)
        ->get(route('administracion.puestos.index', ['por_pagina' => 10]))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('puestos.per_page', 10)
            ->where('puestos.total', $total)
            ->has('puestos.data', 10));

    $this->actingAs($usuario)
        ->get(route('administracion.puestos.index', ['por_pagina' => 'todos']))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('puestos.last_page', 1)
            ->has('puestos.data', $total));

    // Valor fuera del catálogo: el listado conserva su tamaño por defecto.
    $this->actingAs($usuario)
        ->get(route('administracion.puestos.index', ['por_pagina' => 7]))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->where('puestos.per_page', Paginacion::POR_DEFECTO));
});

test('el historial de avisos enviados respeta por_pagina', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $rh = clUsuario('rh_admin');
    Aviso::factory()->count(12)->create();

    $this->actingAs($rh)
        ->get('/rh/avisos?por_pagina=10')
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('avisos.per_page', 10)
            ->where('avisos.total', 12)
            ->has('avisos.data', 10));
});
