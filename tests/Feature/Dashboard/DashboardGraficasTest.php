<?php

use App\Models\Candidato;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Inicio operativo = SOLO el tablero de RH (App\Services\Reportes\TableroRhService):
 * 8 indicadores, embudo por hito máximo, tiempo de contratación por nivel y
 * rotación mensual. El dashboard anterior (cards/gráficas de
 * MetricasRhDashboardService) se retiró por decisión del negocio; el inicio
 * del colaborador conserva su subconjunto personal.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('el inicio operativo trae solo el tablero de RH con sus secciones', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        expect($props)->toHaveKey('tablero')
            ->and($props)->not->toHaveKeys(['cards', 'graficas', 'rotacion', 'proximosAniversarios'])
            ->and($props['tablero'])->toHaveKeys(['summary', 'recruitment_funnel', 'time_to_hire_by_level', 'turnover_monthly', 'filters'])
            ->and($props['tablero']['summary'])->toHaveKeys([
                'plantilla_activa', 'vacantes_abiertas', 'rotacion_mes', 'costo_por_contratacion',
                'tiempo_contratacion', 'permanencia_promedio', 'contratos_por_vencer', 'inversion_campanas_mes',
            ])
            ->and(collect($props['tablero']['recruitment_funnel'])->pluck('clave')->all())
            ->toBe(['interesados', 'viables', 'entrevista', 'psicometricas', 'socioeconomico', 'referencias', 'contratados']);
    });
});

test('el inicio del colaborador solo incluye su subconjunto personal, sin el tablero', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('dashboard'))->assertOk()->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        expect($props)->toHaveKeys(['miExpediente', 'misDocumentosPendientes', 'misVacaciones', 'misSolicitudes', 'avisosPendientes'])
            ->and($props)->not->toHaveKey('tablero');
    });
});

test('el tablero de un gerente solo ofrece y cuenta su propia sucursal', function () {
    $propia = Sucursal::factory()->create(['nombre' => 'Mi sucursal']);
    $ajena = Sucursal::factory()->create(['nombre' => 'Otra sucursal']);

    $gerente = User::factory()->create(['sucursal_principal_id' => $propia->id]);
    $gerente->assignRole('gerente_sucursal');

    Candidato::factory()->create(['sucursal_id' => $propia->id]);
    Candidato::factory()->count(3)->create(['sucursal_id' => $ajena->id]);

    $this->actingAs($gerente)->get(route('dashboard'))->assertOk()->assertInertia(function ($page) {
        $tablero = $page->toArray()['props']['tablero'];

        expect(collect($tablero['filters']['sucursales'])->pluck('nombre'))->not->toContain('Otra sucursal')
            ->and($tablero['recruitment_funnel'][0]['total'])->toBe(1);
    });
});
