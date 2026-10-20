<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Los números de plantilla del Inicio operativo (plantilla activa,
 * capacidad autorizada, vacantes) salen del HEADCOUNT —plazas autorizadas
 * vs. ocupadas por colaboradores vigentes— y de RH → Vacantes, nunca de
 * contar expedientes/carpetas del NAS. Dashboard, Sucursales y Vacantes dan
 * exactamente las mismas cifras.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Cuernavaca']);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->otroPuesto = Puesto::factory()->create(['nombre' => 'Auxiliar sin plantilla']);

    HeadcountTarget::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 5]);

    // 3 ocupan plaza: 2 activos + 1 en incorporación (ya contratado).
    Colaborador::factory()->count(2)->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'estatus' => 'en_incorporacion']);

    // Bajas con expediente/carpeta en el NAS: NO ocupan plaza.
    Colaborador::factory()->count(2)->create([
        'sucursal_principal_id' => $this->sucursal->id,
        'puesto_id' => $this->gestor->id,
        'estatus' => 'inactivo',
        'importado_de' => 'nas_historico',
        'fecha_baja' => '2026-01-15',
    ]);
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'estatus' => 'suspendido']);

    // Una persona activa en un puesto sin plaza autorizada: fuera de plantilla.
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->otroPuesto->id, 'estatus' => 'activo']);

    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    // RH sin sucursal: no suma a la plantilla de ninguna sucursal.
    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

test('el inicio toma plantilla, capacidad y vacantes del headcount y excluye bajas aunque tengan expediente en el NAS', function () {
    $inicio = $this->actingAs($this->rh)->get(route('dashboard'))->assertOk()->viewData('page')['props']['inicio'];
    $plantilla = $inicio['indicadores']['plantilla'];

    expect($plantilla['autorizada'])->toBe(5)
        ->and($plantilla['ocupada'])->toBe(3)
        ->and($plantilla['vacantes'])->toBe(2)
        ->and($plantilla['cumplimiento'])->toBe(60.0)
        ->and($plantilla['fuera_de_plantilla'])->toBe(1)
        ->and($inicio['indicadores']['vacantes'])->toBe(2);

    // Las bajas del NAS siguen existiendo como expediente, pero no cuentan.
    expect(Colaborador::query()->where('sucursal_principal_id', $this->sucursal->id)->where('importado_de', 'nas_historico')->count())->toBe(2);
});

test('dashboard, headcount, sucursales y vacantes dan exactamente los mismos números', function () {
    $props = $this->actingAs($this->rh)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
    $plantilla = $props['inicio']['indicadores']['plantilla'];
    $resumen = $props['tablero']['summary']['plantilla_activa'];

    $canon = app(HeadcountService::class)->totalesGenerales();
    $vacantesModulo = $this->actingAs($this->rh)->get(route('rh.vacantes.index'))->assertOk()->viewData('page')['props']['resumen'];
    $sucursal = $this->actingAs($this->rh)->get(route('administracion.sucursales.show', $this->sucursal))->assertOk()->viewData('page')['props']['totales'];

    // Inicio = HeadcountService.
    expect($plantilla['autorizada'])->toBe($canon['plantilla_autorizada'])
        ->and($plantilla['ocupada'])->toBe($canon['plantilla_actual'])
        ->and($plantilla['vacantes'])->toBe($canon['vacantes'])
        ->and($plantilla['personas_activas'])->toBe($canon['personas_activas'])
        // Tablero completo = mismas cifras.
        ->and($resumen['valor'])->toBe($canon['plantilla_actual'])
        ->and($resumen['autorizada'])->toBe($canon['plantilla_autorizada'])
        ->and($resumen['vacantes'])->toBe($canon['vacantes'])
        // Vacantes del dashboard = pantalla RH → Vacantes.
        ->and($props['inicio']['indicadores']['vacantes'])->toBe($vacantesModulo['plazas'])
        ->and($props['tablero']['summary']['vacantes_abiertas']['valor'])->toBe($vacantesModulo['plazas'])
        // Detalle de la sucursal (única sucursal con plantilla) = mismas cifras.
        ->and($sucursal['plantilla_autorizada'])->toBe($canon['plantilla_autorizada'])
        ->and($sucursal['plantilla_actual'])->toBe($canon['plantilla_actual'])
        ->and($sucursal['vacantes'])->toBe($canon['vacantes'])
        ->and($sucursal['fuera_de_plantilla'])->toBe($canon['fuera_de_plantilla']);
});

test('una baja libera la plaza en el dashboard al momento', function () {
    $antes = $this->actingAs($this->rh)->get(route('dashboard'))->viewData('page')['props']['inicio']['indicadores'];

    Colaborador::query()->where('sucursal_principal_id', $this->sucursal->id)->where('puesto_id', $this->gestor->id)->where('estatus', 'activo')->firstOrFail()
        ->update(['estatus' => 'inactivo', 'fecha_baja' => now()->toDateString()]);
    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    $despues = $this->actingAs($this->rh)->get(route('dashboard'))->viewData('page')['props']['inicio']['indicadores'];

    expect($despues['plantilla']['ocupada'])->toBe($antes['plantilla']['ocupada'] - 1)
        ->and($despues['plantilla']['vacantes'])->toBe($antes['plantilla']['vacantes'] + 1)
        ->and($despues['vacantes'])->toBe($antes['vacantes'] + 1);
});
