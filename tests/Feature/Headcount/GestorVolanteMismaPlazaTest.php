<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use App\Services\Vacantes\VacanteAutoGenerationService;

/*
 * Desde 2026-11-09 Gestor (fijo, con ruta) y Gestor Volante tienen plazas y
 * vacantes PROPIAS, pero se prestan lugar (config headcount.plazas_compartidas):
 * quien no tiene plaza libre de su puesto ocupa una libre del otro. El
 * total ocupado de la pareja es el mismo que cuando eran una sola plaza.
 */
beforeEach(function () {
    $this->sucursal = Sucursal::factory()->create();
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->volante = Puesto::factory()->create(['nombre' => 'Gestor Volante']);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 4]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->volante->id, 'plantilla_autorizada' => 1]);
});

test('gestores y volantes tienen plazas propias en plantilla y vacantes', function () {
    Colaborador::factory()->count(2)->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id]);
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->volante->id]);

    $headcount = app(HeadcountService::class);
    $resumen = collect($headcount->resumenPorPuesto($this->sucursal->id))->keyBy('puesto');

    expect($resumen)->toHaveCount(2)
        ->and($resumen['Gestor']['plantilla_autorizada'])->toBe(4)
        ->and($resumen['Gestor']['plantilla_actual'])->toBe(2)
        ->and($resumen['Gestor']['faltante'])->toBe(2)
        ->and($resumen['Gestor Volante']['plantilla_autorizada'])->toBe(1)
        ->and($resumen['Gestor Volante']['plantilla_actual'])->toBe(1)
        ->and($headcount->vacantesDerivadas($this->sucursal->id, $this->gestor->id))->toBe(2)
        ->and($headcount->vacantesDerivadas($this->sucursal->id, $this->volante->id))->toBe(0);

    $cobertura = $headcount->coberturaDetallada(collect([$this->sucursal->id]));
    expect($cobertura['filas'])->toHaveCount(2)
        ->and($cobertura['totales']['vacantes'])->toBe(2);
});

test('se abre una vacante automática por puesto: Gestor fijo y Gestor volante', function () {
    $servicio = app(VacanteAutoGenerationService::class);
    $servicio->sincronizar($this->sucursal->id, $this->volante->id);
    $servicio->sincronizarTodo();

    $vacantes = Vacante::query()->where('sucursal_id', $this->sucursal->id)->where('generada_automaticamente', true)->get()->keyBy('puesto_id');

    expect($vacantes)->toHaveCount(2)
        ->and($vacantes[$this->gestor->id]->plazas_requeridas)->toBe(4)
        ->and($vacantes[$this->volante->id]->plazas_requeridas)->toBe(1);
});

test('un volante sin plaza de volante libre ocupa una plaza de Gestor', function () {
    Colaborador::factory()->count(2)->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->volante->id]);

    $headcount = app(HeadcountService::class);

    expect($headcount->plantillaDePar($this->sucursal->id, $this->volante->id))->toBe(['autorizada' => 1, 'ocupada' => 1, 'faltantes' => 0])
        ->and($headcount->plantillaDePar($this->sucursal->id, $this->gestor->id))->toBe(['autorizada' => 4, 'ocupada' => 1, 'faltantes' => 3]);
});
