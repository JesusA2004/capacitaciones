<?php

use App\Enums\EstadoVacante;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\PlazasEmpresaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| La vacante de una plaza regional sale de la base: se quita sola al contratar, la
| generación automática no la recrea y un puesto con titular no recibe vacante.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $empresa = Empresa::factory()->create();
    $this->corporativo = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'es_corporativo' => true, 'activo' => true]);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'es_corporativo' => false, 'activo' => true]);
    $dc = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'activo' => true]);
    $this->gr = Puesto::factory()->create(['nombre' => 'Gerente Regional', 'puesto_superior_id' => $dc->id, 'activo' => true]);
    $this->sr = Puesto::factory()->create(['nombre' => 'Subgerente Regional', 'puesto_superior_id' => $this->gr->id, 'activo' => true]);
    Colaborador::factory()->create(['puesto_id' => $dc->id, 'sucursal_principal_id' => $this->corporativo->id, 'estatus' => 'activo']);

    $this->abiertas = fn (Puesto $puesto) => Vacante::query()->where('puesto_id', $puesto->id)
        ->whereIn('estado', [EstadoVacante::Abierta->value])->sum('plazas_disponibles');
});

test('al contratar la plaza regional la vacante desaparece y la generación no la recrea', function (string $puesto) {
    $plaza = $this->{$puesto};
    app(PlazasEmpresaService::class)->asegurar();
    expect(($this->abiertas)($plaza))->toEqual(1);

    // Contratación registrada en una sucursal: la plaza vive en Corporativo.
    Colaborador::factory()->create(['puesto_id' => $plaza->id, 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);
    app(VacanteAutoGenerationService::class)->sincronizar($this->sucursal->id, $plaza->id);
    expect(($this->abiertas)($plaza))->toEqual(0);

    app(PlazasEmpresaService::class)->asegurar();
    app(VacanteAutoGenerationService::class)->sincronizarTodo();
    expect(($this->abiertas)($plaza))->toEqual(0);
})->with(['sr', 'gr']);

test('un puesto regional con titular nunca recibe vacante', function (string $puesto) {
    $plaza = $this->{$puesto};
    Colaborador::factory()->create(['puesto_id' => $plaza->id, 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);

    app(PlazasEmpresaService::class)->asegurar();
    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    expect(Vacante::query()->where('puesto_id', $plaza->id)->count())->toBe(0);
})->with(['sr', 'gr']);
