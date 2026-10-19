<?php

use App\Enums\EstadoVacante;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Reportes\IndicadoresRhService;
use App\Services\Reportes\ReportesRhService;
use App\Services\Reportes\TableroRhService;
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

test('Tablero, Indicadores y Reportes RH tampoco cuentan la automática vieja ya sin faltante', function () {
    vfAutorizar($this->cuernavaca, $this->coordinadora, 1);
    // Misma fila vieja del caso Maribel: automática abierta cuya plaza ya
    // está ocupada porque la persona entró sin pasar por la sincronización.
    Vacante::factory()->create([
        'sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->coordinadora->id, 'generada_automaticamente' => true,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1, 'plazas_cubiertas' => 0,
    ]);
    $maribel = vfPersona($this->cuernavaca, Puesto::factory()->create());
    DB::table('colaboradores')->where('id', $maribel->id)->update(['puesto_id' => $this->coordinadora->id]);

    $rh = clUsuario('rh_admin');

    // La fuente única viva: VacantesListadoService::consulta() (CLAUDE.md §13)
    // no lista la automática vieja, y los tres consumidores vivos (tablero,
    // indicadores y reportes) heredan ese 0 sin recalcular por su cuenta.
    expect(app(VacantesListadoService::class)->consulta($rh)->get())->toBeEmpty()
        ->and(app(TableroRhService::class)->construir($rh)['summary']['vacantes_abiertas']['valor'])->toBe(0)
        ->and(app(IndicadoresRhService::class)->calcular($rh)['vacantes_abiertas'])->toBe(0)
        ->and(app(ReportesRhService::class)->generar('vacantes_abiertas', $rh, [])['filas'])->toBe([]);
});

test('una vacante manual y una automática del mismo puesto no duplican las plazas del listado (P2-9)', function () {
    // Autorizadas 2, ocupadas 0 → faltante real 2. Coexisten una manual (RH)
    // y una automática del MISMO par: antes cada una reclamaba el faltante
    // completo y el listado/resumen contaba 4 plazas donde solo faltan 2.
    vfAutorizar($this->cuernavaca, $this->coordinadora, 2);

    $manual = Vacante::factory()->create([
        'sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->coordinadora->id, 'generada_automaticamente' => false,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 2, 'plazas_disponibles' => 2,
    ]);
    $automatica = Vacante::factory()->create([
        'sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->coordinadora->id, 'generada_automaticamente' => true,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 2, 'plazas_disponibles' => 2,
    ]);

    $rh = clUsuario('rh_admin');
    $listado = app(VacantesListadoService::class);
    $vacantes = $listado->consulta($rh)->get();
    $filas = $listado->filas($vacantes);
    $resumen = $listado->resumen($filas);

    // Conservador: NINGUNA vacante se cancela (ambas se listan), pero el total
    // de plazas disponibles es el faltante real (2), no la suma duplicada (4).
    expect($vacantes->pluck('id')->all())->toContain($manual->id, $automatica->id)
        ->and($resumen['plazas'])->toBe(2)
        ->and(array_sum(array_map(fn (array $f) => $f['plazas_disponibles'], $filas)))->toBe(2)
        ->and($listado->plazasPorSucursal(collect([$this->cuernavaca->id]))->get($this->cuernavaca->id))->toBe(2);
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
