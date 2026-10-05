<?php

use App\Enums\EstadoVacante;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Services\Vacantes\VacantesListadoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Vacantes = max(plantilla autorizada − ocupados reales, 0) como ÚNICA
 * fuente de verdad (docs/HEADCOUNT_Y_VACANTES.md). Caso real: Maribel Díaz
 * Suárez, Coordinadora de Sucursal en Cuernavaca, ocupaba la única plaza y
 * aun así se listaba «1 plaza por cubrir».
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true]);
    $this->coordinadora = Puesto::factory()->create(['nombre' => 'Coordinadora de Sucursal']);
});

function vfAutorizar(Sucursal $sucursal, Puesto $puesto, int $plazas): void
{
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => $plazas]);
}

function vfAbierta(Sucursal $sucursal, Puesto $puesto): ?Vacante
{
    return Vacante::query()->where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)
        ->where('generada_automaticamente', true)->whereIn('estado', EstadoVacante::valoresAbiertos())->first();
}

function vfPersona(Sucursal $sucursal, Puesto $puesto, array $extra = []): Colaborador
{
    return Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo', ...$extra]);
}

test('Maribel ocupa la única plaza autorizada: 1 de 1, 0 vacantes, ninguna automática abierta', function () {
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    vfPersona($this->cuernavaca, $this->coordinadora, ['name' => 'Maribel', 'apellidos' => 'Díaz Suarez']);

    $resultado = app(VacanteAutoGenerationService::class)->sincronizar($this->cuernavaca->id, $this->coordinadora->id);

    expect($resultado['ocupada'])->toBe(1)
        ->and($resultado['faltantes'])->toBe(0)
        ->and(vfAbierta($this->cuernavaca, $this->coordinadora))->toBeNull();
});

test('una automática vieja que quedó abierta con la plaza ocupada se cierra y no se lista como real', function () {
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    // Fila vieja: se abrió cuando la plaza estaba libre y nadie la cerró.
    $vieja = Vacante::factory()->create([
        'sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->coordinadora->id, 'generada_automaticamente' => true,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1, 'plazas_cubiertas' => 0,
    ]);
    // La persona entra por un camino que no pasa por la sincronización (UPDATE directo, como en datos ya cargados).
    $maribel = vfPersona($this->cuernavaca, Puesto::factory()->create());
    DB::table('colaboradores')->where('id', $maribel->id)->update(['puesto_id' => $this->coordinadora->id]);

    $rh = clUsuario('rh_admin');
    $listado = app(VacantesListadoService::class);
    expect($listado->consulta($rh)->pluck('id')->all())->not->toContain($vieja->id);

    $reporte = app(VacanteAutoGenerationService::class)->sincronizarTodo(simular: true);
    expect($reporte['cerradas'])->toBe(1)
        ->and($vieja->fresh()->estado)->toBe(EstadoVacante::Abierta);

    app(VacanteAutoGenerationService::class)->sincronizarTodo();
    expect($vieja->fresh()->estado)->toBe(EstadoVacante::Cancelada)
        ->and($vieja->fresh()->plazas_disponibles)->toBe(0);

    // Idempotente.
    expect(app(VacanteAutoGenerationService::class)->sincronizarTodo()['cambios'])->toBe([]);
});

test('autorizado 1, ocupado 0: 1 vacante; autorizado 3, ocupado 2: 1 vacante', function () {
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    vfAutorizar($this->cuernavaca, $gestor, 3);
    vfPersona($this->cuernavaca, $gestor);
    vfPersona($this->cuernavaca, $gestor);

    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    expect(vfAbierta($this->cuernavaca, $this->coordinadora)?->plazas_disponibles)->toBe(1)
        ->and(vfAbierta($this->cuernavaca, $gestor)?->plazas_disponibles)->toBe(1);

    $filas = collect(app(VacantesListadoService::class)->filas(app(VacantesListadoService::class)->consulta(clUsuario('rh_admin'))->get()))->keyBy('puesto');
    expect($filas['Gestor']['plantilla_actual'])->toBe(2)
        ->and($filas['Gestor']['plantilla_autorizada'])->toBe(3)
        ->and($filas['Gestor']['plazas_disponibles'])->toBe(1)
        ->and($filas['Gestor']['faltantes_reales'])->toBe(1);
});

test('la baja abre la vacante y el alta la cierra, sin llamar a nada más que guardar al colaborador', function () {
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    $maribel = vfPersona($this->cuernavaca, $this->coordinadora);
    expect(vfAbierta($this->cuernavaca, $this->coordinadora))->toBeNull();

    $maribel->update(['estatus' => 'inactivo']);
    expect(vfAbierta($this->cuernavaca, $this->coordinadora)?->plazas_disponibles)->toBe(1);

    $maribel->update(['estatus' => 'activo']);
    expect(vfAbierta($this->cuernavaca, $this->coordinadora))->toBeNull();
});

test('un cambio de sucursal resincroniza origen y destino', function () {
    $cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba', 'activo' => true]);
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    vfAutorizar($cordoba, $this->coordinadora, 1);
    $persona = vfPersona($this->cuernavaca, $this->coordinadora);
    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    expect(vfAbierta($this->cuernavaca, $this->coordinadora))->toBeNull()
        ->and(vfAbierta($cordoba, $this->coordinadora))->not->toBeNull();

    $persona->update(['sucursal_principal_id' => $cordoba->id]);

    expect(vfAbierta($this->cuernavaca, $this->coordinadora)?->plazas_disponibles)->toBe(1)
        ->and(vfAbierta($cordoba, $this->coordinadora))->toBeNull();
});

test('el comando no toca vacantes manuales de RH y --simular no escribe', function () {
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    vfPersona($this->cuernavaca, $this->coordinadora);
    $manual = Vacante::factory()->create([
        'sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->coordinadora->id, 'generada_automaticamente' => false,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1,
    ]);
    $vieja = Vacante::factory()->create([
        'sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->coordinadora->id, 'generada_automaticamente' => true,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1,
    ]);

    $this->artisan('people:sincronizar-vacantes', ['--simular' => true])->assertSuccessful();
    expect($vieja->fresh()->estado)->toBe(EstadoVacante::Abierta);

    $this->artisan('people:sincronizar-vacantes')->assertSuccessful();
    expect($vieja->fresh()->estado)->toBe(EstadoVacante::Cancelada)
        ->and($manual->fresh()->estado)->toBe(EstadoVacante::Abierta)
        ->and($manual->fresh()->plazas_disponibles)->toBe(1);
});
