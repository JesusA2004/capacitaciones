<?php

use App\Enums\EstadoUsuario;
use App\Enums\PeriodicidadNomina;
use App\Models\Colaborador;
use App\Models\NominaLote;
use App\Models\Sucursal;
use App\Services\Nomina\LoteNominaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Caso real: «Sucursales · Semanal · Todas» dio Esperados: 1 con solo el
| actor «Sin clasificación de nómina». La población de nómina se decide por
| permiso + alcance del módulo de nómina, nunca por la navegación, y quien
| no tiene sucursal no reduce el lote a sí mismo.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->sucursalA = Sucursal::factory()->create(['nombre' => 'Sucursal A', 'es_corporativo' => false]);
    $this->sucursalB = Sucursal::factory()->create(['nombre' => 'Sucursal B', 'es_corporativo' => false]);
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true]);

    $persona = fn (?Sucursal $s) => Colaborador::factory()->create([
        'sucursal_principal_id' => $s?->id,
        'estatus' => EstadoUsuario::Activo,
        'salario_diario' => 300,
        'fecha_ingreso' => '2024-01-01',
    ]);

    collect(range(1, 3))->each(fn () => $persona($this->sucursalA));
    collect(range(1, 4))->each(fn () => $persona($this->sucursalB));
    collect(range(1, 5))->each(fn () => $persona($this->corporativo));
    $this->sinSucursal = $persona(null);

    // Actor super_admin SIN sucursal (como la cuenta real que lo reportó).
    $this->superAdmin = clUsuario('super_admin', ['sucursal_principal_id' => null, 'fecha_ingreso' => '2024-01-01']);
    $this->lotes = app(LoteNominaService::class);
    $this->fecha = CarbonImmutable::parse('2026-10-14');
});

test('semanal «todas las sucursales» incluye a las 7 personas de sucursales operativas y no al corporativo', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, $this->fecha, $this->superAdmin);

    $sinClasificar = collect($lote->advertencias)->where('sin_clasificacion', true)->pluck('colaborador_id');

    expect($lote->esperados)->toBe(7)
        ->and($lote->preparados)->toBe(7)
        ->and($lote->errores)->toBe([])
        ->and($sinClasificar)->toContain($this->sinSucursal->id)
        ->and($sinClasificar)->toContain($this->superAdmin->colaborador_id);
});

test('quincenal incluye solo a las 5 personas del corporativo', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Quincenal, $this->fecha, $this->superAdmin);

    expect($lote->esperados)->toBe(5)->and($lote->preparados)->toBe(5);
});

test('rh_admin con nómina global ve la misma población que super_admin', function () {
    $rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursalA->id, 'salario_diario' => 300, 'fecha_ingreso' => '2024-01-01']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, $this->fecha, $rh);

    // 7 + la propia persona de RH (también es de la Sucursal A).
    expect($lote->esperados)->toBe(8);
});

test('el filtro de sucursales acota el semanal sin mezclar el corporativo', function () {
    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, $this->fecha, $this->superAdmin, [$this->sucursalB->id, $this->corporativo->id]);

    expect($lote->esperados)->toBe(4);
});

test('un gerente de sucursal con alcance de nómina «sucursal» solo prepara su sucursal', function () {
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursalB->id, 'salario_diario' => 300, 'fecha_ingreso' => '2024-01-01']);
    $gerente->givePermissionTo(['nomina.recibos.ver', 'nomina.recibos.crear', 'nomina.recibos.ver_sucursal']);

    $lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, $this->fecha, $gerente);

    expect($lote->esperados)->toBe(5); // 4 de B + el propio gerente de B
});

test('sin alcance de nómina sobre sucursales no se arma en silencio un lote de una persona', function () {
    $colaborador = clUsuario('colaborador', ['sucursal_principal_id' => null]);
    $colaborador->givePermissionTo('nomina.recibos.crear');

    expect(fn () => $this->lotes->preparar(PeriodicidadNomina::Semanal, $this->fecha, $colaborador))
        ->toThrow(ValidationException::class);

    expect(NominaLote::query()->count())->toBe(0);
});
