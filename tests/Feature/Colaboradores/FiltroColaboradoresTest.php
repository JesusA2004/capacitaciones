<?php

use App\Models\Colaborador;
use App\Services\Colaboradores\FiltroColaboradoresService;

/*
| Capa de consulta compartida de los listados de colaboradores (web de
| Expedientes, API de expedientes y API de colaboradores).
*/

test('aplica búsqueda, sucursal y departamento con un solo criterio; el correo solo cuando se pide', function () {
    $estructura = clEstructura();
    $buscado = Colaborador::factory()->create(['name' => 'Zacarías', 'correo_personal' => 'zeta@correo.test', 'sucursal_principal_id' => $estructura['sucursal']->id]);
    Colaborador::factory()->create(['name' => 'Otra persona', 'correo_personal' => 'otra@correo.test']);
    $filtro = app(FiltroColaboradoresService::class);

    expect($filtro->aplicar(Colaborador::query(), ['busqueda' => 'zacarias'])->pluck('id')->all())->toBe([$buscado->id])
        ->and($filtro->aplicar(Colaborador::query(), ['busqueda' => 'zeta@correo'])->count())->toBe(0)
        ->and($filtro->aplicar(Colaborador::query(), ['busqueda' => 'zeta@correo'], conCorreo: true)->pluck('id')->all())->toBe([$buscado->id])
        ->and($filtro->aplicar(Colaborador::query(), ['sucursal_id' => $estructura['sucursal']->id])->pluck('id')->all())->toContain($buscado->id)
        ->and($filtro->aplicar(Colaborador::query(), ['sucursal_id' => 0, 'busqueda' => ''])->count())->toBe(Colaborador::query()->count());
});
