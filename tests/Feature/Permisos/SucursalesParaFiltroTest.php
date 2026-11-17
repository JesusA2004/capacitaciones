<?php

use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Catálogo de sucursales de los filtros (AlcanceOrganizacionalService::
| sucursalesParaFiltro): un filtro nunca ofrece una sucursal ajena.
*/

test('el gerente de sucursal solo ve su sucursal en los filtros; RH ve todas', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $estructura = clEstructura();
    $ajena = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Sucursal ajena']);
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $rh = clUsuario('super_admin');
    $alcance = app(AlcanceOrganizacionalService::class);

    $delGerente = $alcance->sucursalesParaFiltro($gerente, 'candidatos')->pluck('id');
    $deRh = $alcance->sucursalesParaFiltro($rh, null, ['id', 'nombre', 'empresa_id']);

    expect($delGerente->all())->toBe([$estructura['sucursal']->id])
        ->and($deRh->pluck('id'))->toContain($ajena->id)
        ->and($deRh->first()?->getAttributes())->toHaveKeys(['id', 'nombre', 'empresa_id']);
});
