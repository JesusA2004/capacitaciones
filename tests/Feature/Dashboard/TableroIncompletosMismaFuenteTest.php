<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Services\Reportes\TableroRhService;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Cierre Fase 1 · P2 «el tablero del gerente dice "nada urgente" mientras sus
| listas muestran faltantes». Causa: el indicador «Expedientes incompletos»
| solo se calculaba con expedientes.revisar (null para gerencias) y con otro
| alcance que la lista de Expedientes a la que lleva su enlace. Ahora usa el
| mismo permiso que el destino y la misma consulta que «Datos incompletos».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->otra = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);

    // Dos personas de su sucursal sin CURP y una de otra sucursal (fuera de su alcance).
    Colaborador::factory()->count(2)->create(['estatus' => EstadoUsuario::Activo, 'sucursal_principal_id' => $this->sucursal->id, 'curp' => null]);
    Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo, 'sucursal_principal_id' => $this->otra->id, 'curp' => null]);
});

test('el gerente ve el conteo de expedientes incompletos y coincide con la lista que abre su enlace', function () {
    $tablero = app(TableroRhService::class)->construir($this->gerente);
    $valor = $tablero['summary']['expedientes_incompletos']['valor'];
    $enlace = $tablero['enlaces']['incompletos'];

    expect($valor)->toBeInt()->toBeGreaterThanOrEqual(2)
        ->and($enlace)->toContain('datos=incompletos');

    $this->actingAs($this->gerente)->get($enlace)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('colaboradores.total', $valor));
});

test('el Inicio operativo y el tablero dan la misma cifra (una sola fuente)', function () {
    $this->actingAs($this->gerente)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inicio.indicadores.expedientes_incompletos', fn ($valor) => $valor >= 2)
            ->where('tablero.summary.expedientes_incompletos.valor', fn ($valor) => $valor >= 2));
});

test('quien no puede abrir Expedientes no recibe cifra (null), nunca un cero engañoso', function () {
    $sinExpedientes = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);

    $tablero = app(TableroRhService::class)->construir($sinExpedientes);

    expect($tablero['summary']['expedientes_incompletos']['valor'])->toBeNull()
        ->and($tablero['enlaces']['incompletos'])->toBeNull();
});
