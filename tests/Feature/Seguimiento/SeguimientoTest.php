<?php

use App\Enums\MetodoVinculoReportes;
use App\Enums\TipoVinculoReportes;
use App\Models\Sucursal;
use App\Models\VinculoReportes;
use App\Services\Seguimiento\VinculoReportesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Seguimiento (Fase 2, docs/SEGUIMIENTO.md): PEOPLE lee la API v1 de
| Reportes (aquí con Http::fake), suma por sucursal de PEOPLE los branches
| vinculados, acota por alcance y nunca truena si Reportes falla.
*/

const SEG_URL = 'https://reportes.prueba';

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    config([
        'features.seguimiento' => true,
        'seguimiento.reportes.url' => SEG_URL,
        'seguimiento.reportes.token' => 'token-de-prueba',
        'seguimiento.reportes.cache_minutos' => 0,
        'seguimiento.reportes.espera_reintento_ms' => 0,
    ]);

    $this->estructura = clEstructura();
    $this->tula = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id, 'nombre' => 'Tula']);
    $this->cuerna = $this->estructura['sucursal'];
});

/** Semana de la respuesta weekly-activity. */
function segSemana(int $id, float $colocacion, int $ops, float $recuperacion): array
{
    return ['period_id' => $id, 'placement' => ['amount' => $colocacion, 'operations' => $ops], 'recovery' => ['total' => $recuperacion]];
}

function segFalsoReportes(array $extra = []): void
{
    $semanas = [
        ['id' => 13, 'code' => 'W-2026-22', 'label' => 'Semana 22 - Mayo 2026', 'sequence' => 22, 'index' => 1, 'start_date' => '2026-05-25', 'end_date' => '2026-05-31'],
        ['id' => 14, 'code' => 'W-2026-23', 'label' => 'Semana 23 - Junio 2026', 'sequence' => 23, 'index' => 2, 'start_date' => '2026-06-01', 'end_date' => '2026-06-07'],
    ];
    $cero = ['placement' => ['amount' => 0, 'operations' => 0], 'recovery' => ['total' => 0]];

    Http::fake([
        SEG_URL.'/api/v1/periods?*' => Http::response([
            'data' => [
                ['id' => 21, 'code' => 'M-2026-06', 'type' => 'monthly', 'label' => 'Junio 2026', 'start_date' => '2026-05-25', 'end_date' => '2026-06-21', 'radiography_status' => 'generated', 'component_period_ids' => [13, 14]],
                ['id' => 24, 'code' => 'TEST', 'type' => 'monthly', 'label' => 'Prueba', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'radiography_status' => 'generated', 'component_period_ids' => []],
            ],
            'meta' => ['last_page' => 1],
        ]),
        SEG_URL.'/api/v1/periods/21/weekly-activity*' => Http::response([
            'status' => 'ok', 'message' => null, 'calculated_at' => '2026-09-21T17:36:46Z',
            'weeks' => $semanas,
            'branches' => ['attribution' => 'fact_branch_id', 'data' => [
                ['branch_id' => 99, 'branch' => ['id' => 99, 'code' => 'TULA', 'name' => 'TULA'], 'weeks' => [segSemana(13, 100, 2, 50), segSemana(14, 200, 3, 70)], 'outside_weeks' => $cero, 'month_total' => ['placement' => ['amount' => 300, 'operations' => 5], 'recovery' => ['total' => 120]]],
                ['branch_id' => 89, 'branch' => ['id' => 89, 'code' => 'CUERNAVACA', 'name' => 'CUERNAVACA'], 'weeks' => [segSemana(13, 10, 1, 5), segSemana(14, 20, 1, 5)], 'outside_weeks' => $cero, 'month_total' => ['placement' => ['amount' => 30, 'operations' => 2], 'recovery' => ['total' => 10]]],
            ], 'unassigned' => null],
            'employees' => ['data' => [
                ['employee' => ['id' => 73, 'employee_ids' => [73], 'full_name' => 'ADRIAN DIAZ MENA', 'position' => null], 'assigned_branch_id' => 99, 'branch' => ['id' => 99, 'name' => 'TULA'], 'weeks' => [segSemana(13, 100, 2, 50), segSemana(14, 200, 3, 70)], 'outside_weeks' => $cero, 'month_total' => ['placement' => ['amount' => 300, 'operations' => 5], 'recovery' => ['total' => 120]]],
                ['employee' => ['id' => 80, 'employee_ids' => [80], 'full_name' => 'OTRO GESTOR', 'position' => null], 'assigned_branch_id' => 89, 'branch' => ['id' => 89, 'name' => 'CUERNAVACA'], 'weeks' => [segSemana(13, 10, 1, 5), segSemana(14, 20, 1, 5)], 'outside_weeks' => $cero, 'month_total' => ['placement' => ['amount' => 30, 'operations' => 2], 'recovery' => ['total' => 10]]],
            ], 'unidentified' => []],
        ]),
        SEG_URL.'/api/v1/periods/21/branches*' => Http::response(['status' => 'ok', 'data' => [
            ['branch_id' => 99, 'name' => 'TULA', 'kpis' => ['placement' => ['amount' => 300, 'credits' => 5], 'recovery' => ['total' => 120], 'portfolio' => ['total' => 1000, 'overdue' => 100]]],
            ['branch_id' => 89, 'name' => 'CUERNAVACA', 'kpis' => ['placement' => ['amount' => 30, 'credits' => 2], 'recovery' => ['total' => 10], 'portfolio' => ['total' => 500, 'overdue' => 50]]],
        ]]),
        SEG_URL.'/api/v1/periods/21/employees*' => Http::response(['status' => 'ok', 'data' => [
            ['employee' => ['id' => 73, 'employee_ids' => [73]], 'kpis' => ['portfolio' => ['total' => 800, 'overdue' => 80]]],
        ], 'meta' => ['last_page' => 1]]),
        SEG_URL.'/api/v1/employees/73/periods*' => Http::response(['data' => [
            ['period' => ['id' => 21, 'label' => 'Junio 2026'], 'status' => 'ok', 'kpis' => ['placement' => ['amount' => 300], 'recovery' => ['total' => 120], 'portfolio' => ['total' => 800, 'overdue' => 80]]],
        ]]),
        SEG_URL.'/api/v1/branches*' => Http::response(['data' => [
            ['id' => 99, 'code' => 'TULA', 'name' => 'TULA', 'sucursal' => null, 'region' => null],
            ['id' => 89, 'code' => 'CUERNAVACA', 'name' => 'CUERNAVACA', 'sucursal' => null, 'region' => null],
        ], 'meta' => ['last_page' => 1]]),
        SEG_URL.'/api/v1/employees?*' => Http::response(['data' => [
            ['id' => 73, 'employee_code' => '7', 'full_name' => 'ADRIAN DIAZ MENA', 'position' => null, 'branch_id' => 99, 'branch' => ['name' => 'TULA']],
        ], 'meta' => ['last_page' => 1]]),
        ...$extra,
    ]);
}

function segVincularSucursales(object $t): void
{
    $admin = clUsuario('super_admin');
    $servicio = app(VinculoReportesService::class);
    $servicio->vincularSucursal($admin, $t->tula, 99, 'TULA', 'TULA', MetodoVinculoReportes::Manual);
    $servicio->vincularSucursal($admin, $t->cuerna, 89, 'CUERNAVACA', 'CUERNAVACA', MetodoVinculoReportes::Manual);
}

it('el tablero suma por sucursal de PEOPLE los branches vinculados, con semanas y KPIs oficiales del mes', function () {
    segFalsoReportes();
    segVincularSucursales($this);

    $this->actingAs(clUsuario('rh_admin'))
        ->get('/seguimiento/sucursales')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Seguimiento/Sucursales')
            ->missing('datos')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('datos.error', null)
                ->where('datos.datos.periodo.mes.id', 21)
                // El mes de prueba sin semanas componentes no se ofrece.
                ->has('datos.datos.periodo.meses', 1)
                ->has('datos.datos.tablero.semanas', 2)
                ->has('datos.datos.tablero.sucursales', 2)
                ->where('datos.datos.tablero.sucursales.0.nombre', 'Tula')
                ->where('datos.datos.tablero.sucursales.0.colocacion', 300)
                ->where('datos.datos.tablero.sucursales.0.mora', 10)
                ->where('datos.datos.tablero.totales.colocacion', 330)
                ->where('datos.datos.tablero.serie_semanal.1.colocacion', 220)
            ));
});

it('el gerente de sucursal solo ve su sucursal', function () {
    segFalsoReportes();
    segVincularSucursales($this);
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->tula->id]);

    $this->actingAs($gerente)
        ->get('/seguimiento/gestores?mes=21')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Seguimiento/Gestores')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('datos.datos.tablero.gestores', 1)
                ->where('datos.datos.tablero.gestores.0.reportes_id', 73)
                ->where('datos.datos.tablero.gestores.0.sucursal', 'Tula')
                ->where('datos.datos.tablero.gestores.0.mora', 10)
            ));

    // El gestor de otra sucursal está fuera de su alcance.
    $this->actingAs($gerente)->get('/seguimiento/gestores/80?mes=21')->assertForbidden();
    $this->actingAs($gerente)->get('/seguimiento/gestores/73?mes=21')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Seguimiento/Gestor')
            ->where('datos.error', null)
            ->has('datos.datos.detalle.semanal', 2)
            ->where('datos.datos.detalle.mes.cartera', 800));
});

it('si Reportes no responde la pantalla muestra el motivo en vez de tronar', function () {
    Http::fake([SEG_URL.'/*' => Http::response(['message' => 'Server Error'], 500)]);

    $this->actingAs(clUsuario('rh_admin'))
        ->get('/seguimiento/sucursales')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('datos.datos', null)
                ->where('datos.error', 'Reportes tuvo un error interno al preparar estos datos. Intenta más tarde.')
            ));

    // 1 intento + 2 reintentos ante un 5xx.
    Http::assertSentCount(3);
});

it('vincular exige seguimiento.vincular y reasignar conserva el historial', function () {
    segFalsoReportes();

    $this->actingAs(clUsuario('gerente_sucursal'))
        ->post('/seguimiento/vinculos/sucursales', ['reportes_id' => 99, 'sucursal_id' => $this->tula->id])
        ->assertForbidden();

    $rh = clUsuario('rh_admin');
    $this->actingAs($rh)->post('/seguimiento/vinculos/sucursales', ['reportes_id' => 99, 'sucursal_id' => $this->tula->id])->assertRedirect();
    $this->actingAs($rh)->post('/seguimiento/vinculos/sucursales', ['reportes_id' => 99, 'sucursal_id' => $this->cuerna->id])->assertRedirect();

    $vinculos = VinculoReportes::query()->where('tipo', TipoVinculoReportes::Sucursal->value)->where('reportes_id', 99)->orderBy('id')->get();
    expect($vinculos)->toHaveCount(2)
        ->and($vinculos[0]->activo)->toBeFalse()
        ->and($vinculos[0]->desactivado_por)->toBe($rh->id)
        ->and($vinculos[1]->activo)->toBeTrue()
        ->and($vinculos[1]->sucursal_id)->toBe($this->cuerna->id)
        // El nombre se toma de Reportes, no del formulario.
        ->and($vinculos[1]->reportes_nombre)->toBe('TULA');

    // Un branch que no existe en Reportes se rechaza.
    $this->actingAs($rh)->post('/seguimiento/vinculos/sucursales', ['reportes_id' => 5555, 'sucursal_id' => $this->tula->id])
        ->assertSessionHasErrors('reportes_id');
});

it('quien no tiene seguimiento.ver no entra', function () {
    $this->actingAs(clUsuario('colaborador'))->get('/seguimiento')->assertForbidden();
});
