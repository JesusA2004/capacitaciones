<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Solicitudes\BajaColaboradorService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('headcount autorizado por encima de la plantilla actual abre una vacante automatica con sus plazas', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 3]);

    app(VacanteAutoGenerationService::class)->sincronizar($sucursal->id, $puesto->id);

    $vacante = Vacante::where('sucursal_id', $sucursal->id)
        ->where('puesto_id', $puesto->id)
        ->where('generada_automaticamente', true)
        ->first();

    expect($vacante)->not->toBeNull();
    expect($vacante->plazas_requeridas)->toBe(3);
    expect($vacante->plazas_disponibles)->toBe(3);
    expect($vacante->estado->value)->toBe('abierta');
});

test('dar de alta a un colaborador baja las plazas disponibles de la vacante automatica', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 2]);
    app(VacanteAutoGenerationService::class)->sincronizar($sucursal->id, $puesto->id);

    $sistema = User::factory()->create();
    $nuevo = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);

    app(MovimientoLaboralService::class)->registrarAlta($nuevo, $sistema);

    $vacante = Vacante::where('sucursal_id', $sucursal->id)
        ->where('puesto_id', $puesto->id)
        ->where('generada_automaticamente', true)
        ->first();

    expect($vacante->plazas_disponibles)->toBe(1);
    expect($vacante->plazas_requeridas)->toBe(1);
});

test('dar de baja a un colaborador sube las plazas disponibles de la vacante automatica', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 2]);

    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);

    app(VacanteAutoGenerationService::class)->sincronizar($sucursal->id, $puesto->id);

    // Con 2 activos y 2 autorizados, faltantes=0: todavia no hay vacante automatica.
    expect(Vacante::where('sucursal_id', $sucursal->id)->where('generada_automaticamente', true)->exists())->toBeFalse();

    $actor = User::factory()->create();
    $actor->assignRole('rh_admin');

    app(BajaColaboradorService::class)->ejecutar($colaborador, $actor, 'Renuncia de prueba.');

    $vacante = Vacante::where('sucursal_id', $sucursal->id)
        ->where('puesto_id', $puesto->id)
        ->where('generada_automaticamente', true)
        ->first();

    expect($vacante)->not->toBeNull();
    expect($vacante->plazas_disponibles)->toBe(1);
});

test('subir o bajar el headcount autorizado actualiza las plazas de la vacante automatica existente sin duplicarla', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    $target = HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 2]);

    $servicio = app(VacanteAutoGenerationService::class);

    $servicio->sincronizar($sucursal->id, $puesto->id);
    $vacante = Vacante::where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)->first();
    expect($vacante->plazas_requeridas)->toBe(2);

    $target->update(['plantilla_autorizada' => 4]);
    $servicio->sincronizar($sucursal->id, $puesto->id);
    expect($vacante->fresh()->plazas_requeridas)->toBe(4);

    $target->update(['plantilla_autorizada' => 1]);
    $servicio->sincronizar($sucursal->id, $puesto->id);
    expect($vacante->fresh()->plazas_requeridas)->toBe(1);

    $target->update(['plantilla_autorizada' => 0]);
    $servicio->sincronizar($sucursal->id, $puesto->id);
    expect($vacante->fresh()->estado->value)->toBe('cancelada');
    expect($vacante->fresh()->plazas_disponibles)->toBe(0);

    expect(Vacante::where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)->where('generada_automaticamente', true)->count())->toBe(1);
});

test('sin headcount configurado para el par no se genera ninguna vacante automatica', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();

    app(VacanteAutoGenerationService::class)->sincronizar($sucursal->id, $puesto->id);

    expect(Vacante::where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)->exists())->toBeFalse();
});

test('sincronizar varias veces con el mismo faltante no duplica la vacante automatica', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 2]);

    $servicio = app(VacanteAutoGenerationService::class);
    $servicio->sincronizar($sucursal->id, $puesto->id);
    $servicio->sincronizar($sucursal->id, $puesto->id);
    $servicio->sincronizar($sucursal->id, $puesto->id);

    expect(Vacante::where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)->where('generada_automaticamente', true)->count())->toBe(1);
});
