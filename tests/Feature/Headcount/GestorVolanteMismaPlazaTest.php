<?php

use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use App\Services\Vacantes\VacanteAutoGenerationService;

/*
 * RH contrata "un gestor": entra como volante y después toma ruta. Para
 * plantilla y vacantes es la misma plaza (config/headcount.php).
 */
beforeEach(function () {
    $this->sucursal = Sucursal::factory()->create();
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->volante = Puesto::factory()->create(['nombre' => 'Gestor volante']);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 4]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->volante->id, 'plantilla_autorizada' => 1]);
});

test('gestores y volantes forman una sola plaza en plantilla y vacantes', function () {
    Colaborador::factory()->count(2)->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id]);
    Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'puesto_id' => $this->volante->id]);

    $headcount = app(HeadcountService::class);
    $resumen = $headcount->resumenPorPuesto($this->sucursal->id);

    expect($resumen)->toHaveCount(1)
        ->and($resumen[0]['puesto'])->toBe('Gestor')
        ->and($resumen[0]['plantilla_autorizada'])->toBe(5)
        ->and($resumen[0]['plantilla_actual'])->toBe(3)
        ->and($resumen[0]['faltante'])->toBe(2)
        ->and($headcount->vacantesDerivadas($this->sucursal->id, $this->gestor->id))->toBe(2)
        ->and($headcount->vacantesDerivadas($this->sucursal->id, $this->volante->id))->toBe(0);

    $cobertura = $headcount->coberturaDetallada(collect([$this->sucursal->id]));
    expect($cobertura['filas'])->toHaveCount(1)
        ->and($cobertura['totales']['vacantes'])->toBe(2);
});

test('solo se abre una vacante automática de Gestor aunque se sincronice desde el volante', function () {
    $servicio = app(VacanteAutoGenerationService::class);
    $servicio->sincronizar($this->sucursal->id, $this->volante->id);
    $servicio->sincronizarTodo();

    $vacantes = Vacante::query()->where('sucursal_id', $this->sucursal->id)->where('generada_automaticamente', true)->get();

    expect($vacantes)->toHaveCount(1)
        ->and($vacantes[0]->puesto_id)->toBe($this->gestor->id)
        ->and($vacantes[0]->plazas_requeridas)->toBe(5);
});

test('una vacante automática vieja de volante se convierte en la de gestor, sin duplicar', function () {
    Vacante::factory()->create([
        'sucursal_id' => $this->sucursal->id,
        'puesto_id' => $this->volante->id,
        'generada_automaticamente' => true,
        'estado' => 'abierta',
    ]);

    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    $abiertas = Vacante::query()->where('sucursal_id', $this->sucursal->id)->where('estado', 'abierta')->get();

    expect($abiertas)->toHaveCount(1)
        ->and($abiertas[0]->puesto_id)->toBe($this->gestor->id);
});
