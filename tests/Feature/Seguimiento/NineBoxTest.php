<?php

use App\Enums\MetodoVinculoReportes;
use App\Models\Colaborador;
use App\Models\NineBoxCalibracion;
use App\Models\Sucursal;
use App\Services\Seguimiento\VinculoReportesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Nine Box (docs/NINE_BOX.md): desempeño con datos de Reportes (Http::fake)
| × potencial (calibración), acotada por el alcance del módulo «nine_box».
| Calibrar deja historial y nunca alcanza a gente fuera de su alcance.
*/

it('ubica al gestor por desempeño de Reportes y calibración, respeta el alcance y conserva el historial', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $url = 'https://reportes.prueba';
    config([
        'features.seguimiento' => true,
        'features.nine_box' => true,
        'seguimiento.reportes.url' => $url,
        'seguimiento.reportes.token' => 'token',
        'seguimiento.reportes.cache_minutos' => 0,
    ]);

    $estructura = clEstructura();
    $tula = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Tula']);
    $semana = fn (int $id, float $c, float $r): array => ['period_id' => $id, 'placement' => ['amount' => $c, 'operations' => 1], 'recovery' => ['total' => $r]];
    $gestor = fn (int $id, string $nombre, int $branch, float $c, float $r): array => [
        'employee' => ['id' => $id, 'employee_ids' => [$id], 'full_name' => $nombre, 'position' => null],
        'assigned_branch_id' => $branch, 'branch' => ['id' => $branch, 'name' => $nombre],
        'weeks' => [$semana(13, $c, $r)], 'month_total' => ['placement' => ['amount' => $c, 'operations' => 1], 'recovery' => ['total' => $r]],
    ];

    Http::fake([
        $url.'/api/v1/periods?*' => Http::response(['data' => [
            ['id' => 21, 'code' => 'M-2026-06', 'label' => 'Junio 2026', 'start_date' => '2026-05-25', 'end_date' => '2026-06-21', 'radiography_status' => 'generated', 'component_period_ids' => [13]],
        ], 'meta' => ['last_page' => 1]]),
        $url.'/api/v1/periods/21/weekly-activity*' => Http::response([
            'status' => 'ok',
            'weeks' => [['id' => 13, 'index' => 1, 'label' => 'Semana 22', 'start_date' => '2026-05-25', 'end_date' => '2026-05-31']],
            'employees' => ['data' => [$gestor(73, 'ADRIAN DIAZ MENA', 99, 300, 120), $gestor(80, 'OTRO GESTOR', 89, 30, 10)]],
        ]),
        $url.'/api/v1/periods/21/employees*' => Http::response(['data' => [
            ['employee' => ['id' => 73, 'employee_ids' => [73]], 'kpis' => ['portfolio' => ['total' => 1000, 'overdue' => 100]]],
        ], 'meta' => ['last_page' => 1]]),
    ]);

    $admin = clUsuario('super_admin');
    $vinculos = app(VinculoReportesService::class);
    $vinculos->vincularSucursal($admin, $tula, 99, 'TULA', 'TULA', MetodoVinculoReportes::Manual);
    $vinculos->vincularSucursal($admin, $estructura['sucursal'], 89, 'CUERNAVACA', 'CUERNAVACA', MetodoVinculoReportes::Manual);
    $adrian = Colaborador::factory()->create(['sucursal_principal_id' => $tula->id]);
    $vinculos->vincularColaborador($admin, $adrian, 73, 'ADRIAN DIAZ MENA', null, MetodoVinculoReportes::Manual);

    $rh = clUsuario('rh_admin');
    $calibrar = fn (string $nivel): array => [
        'colaborador_id' => $adrian->id, 'periodo_tipo' => 'mes', 'periodo_clave' => 'M-2026-06',
        'nivel' => $nivel, 'comentario' => 'Abre cartera nueva cada semana y guía a su ruta.',
    ];

    // Un gerente de OTRA sucursal no lo ve ni lo calibra.
    $ajeno = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->actingAs($ajeno)->post('/seguimiento/nine-box/calibraciones', $calibrar('alto'))->assertForbidden();

    // RH calibra dos veces: queda el historial completo, vale la más reciente.
    $this->actingAs($rh)->post('/seguimiento/nine-box/calibraciones', $calibrar('medio'))->assertSessionHasNoErrors();
    $this->actingAs($rh)->post('/seguimiento/nine-box/calibraciones', $calibrar('alto'))->assertSessionHasNoErrors();
    expect(NineBoxCalibracion::query()->where('colaborador_id', $adrian->id)->count())->toBe(2);

    // Colocación y recuperación = percentil 100; mora 10 % = 75 pts →
    // desempeño 93.8 (alto). Potencial = calibración alta (90) → Estrella.
    $this->actingAs($rh)->get('/seguimiento/nine-box?tipo=mes&periodo=M-2026-06')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Seguimiento/NineBox')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('datos.error', null)
                ->has('datos.datos.personas', 2)
                ->where('datos.datos.personas.0.id', 73)
                ->where('datos.datos.personas.0.desempeno.puntaje', 93.8)
                ->where('datos.datos.personas.0.potencial.puntaje', 90)
                ->where('datos.datos.personas.0.cuadrante', 'estrella')
                ->where('datos.datos.personas.1.estado', 'sin_vincular')
                ->where('datos.datos.conteos.estrella', 1)
            ));

    // El gerente ajeno solo ve a su sucursal (el gestor sin vincular de Cuernavaca).
    $this->actingAs($ajeno)->get('/seguimiento/nine-box')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('datos.datos.personas', 1)
            ->where('datos.datos.personas.0.id', 80)
        ));
});
