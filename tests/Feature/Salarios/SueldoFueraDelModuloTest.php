<?php

use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\MovimientoLaboral;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Encargo Etapa 1 §7: ser gerente o tener permisos generales de expedientes no
 * da acceso a sueldos, ni en pantalla ni por API. El importe centinela no debe
 * aparecer en ninguna respuesta del gerente.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->sucursal = Sucursal::factory()->create();
    $this->gerente = User::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);
    $this->gerente->assignRole('gerente_sucursal');

    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id,
        'sueldo_mensual' => 17345.67,
        'salario_diario' => 578.19,
    ]);
    $this->contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id, 'sueldo_mensual' => 17345.67]);
    MovimientoLaboral::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'tipo_movimiento' => TipoMovimientoLaboral::CambioSueldo->value,
        'observaciones' => 'Sueldo mensual: $10000.00 → $17345.67',
    ]);
});

test('el gerente no recibe el sueldo en el expediente y RH sí', function () {
    $respuesta = $this->actingAs($this->gerente)->get(route('rh.expedientes.show', $this->colaborador))->assertOk();

    expect($respuesta->getContent())->not->toContain('17345');

    $respuesta->assertInertia(fn ($page) => $page->where('puedeVerSueldo', false)->where('colaborador.sueldo_mensual', null));

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)->get(route('rh.expedientes.show', $this->colaborador))
        ->assertInertia(fn ($page) => $page->where('puedeVerSueldo', true)->where('colaborador.sueldo_mensual', '17345.67'));
});

test('el auxiliar de RH ve el sueldo del expediente pero no el módulo de salarios', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $this->actingAs($auxiliar)->get(route('rh.expedientes.show', $this->colaborador))
        ->assertInertia(fn ($page) => $page->where('puedeVerSueldo', true));

    $this->actingAs($auxiliar)->get(route('salarios.index'))->assertForbidden();
});

test('la ficha del ciclo laboral no le muestra el sueldo al gerente', function () {
    $respuesta = $this->actingAs($this->gerente)->get(route('rh.colaboradores.ciclo', $this->colaborador))->assertOk();

    expect($respuesta->getContent())->not->toContain('17345');
});

test('la API de contratos, alta y ciclo no le entrega el sueldo al gerente', function () {
    Sanctum::actingAs($this->gerente);

    foreach ([
        route('api.v1.rh.colaboradores.contratos', $this->colaborador),
        route('api.v1.rh.colaboradores.alta', $this->colaborador),
        route('api.v1.rh.colaboradores.ciclo', $this->colaborador),
    ] as $url) {
        // Un 500 o un 403 tampoco traen el sueldo: la respuesta debe ser real.
        expect($this->getJson($url)->assertOk()->getContent())->not->toContain('17345');
    }
});

test('el colaborador serializado nunca incluye sueldos', function () {
    expect($this->colaborador->refresh()->toArray())->not->toHaveKeys(['sueldo_mensual', 'salario_diario']);
});

test('quien no puede ver el sueldo tampoco lo cambia al editar datos laborales', function () {
    $this->gerente->givePermissionTo('expedientes.editar');

    $this->actingAs($this->gerente)->put(route('rh.expedientes.datos-laborales.update', $this->colaborador), [
        'sucursal_principal_id' => $this->sucursal->id,
        'sueldo_mensual' => 99999,
    ]);

    expect((float) $this->colaborador->refresh()->sueldo_mensual)->toBe(17345.67);
});
