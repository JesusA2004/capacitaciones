<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoSolicitudInterna;
use App\Models\MovimientoLaboral;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\Reportes\ReportesRhService;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Ver CLAUDE.md ("cierre real MR. LANA PEOPLE"), secciones 28-30: tres bugs
 * de reportes ya corregidos, cada uno probado por separado para que una
 * regresión futura señale exactamente cuál se rompió.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->rhAdmin = User::factory()->create();
    $this->rhAdmin->assignRole('rh_admin');
    $this->servicio = app(ReportesRhService::class);
});

test('bajas por mes SI cuenta a los colaboradores dados de baja (antes siempre daba cero)', function () {
    $colaborador = User::factory()->create();

    MovimientoLaboral::factory()->create([
        'user_id' => $colaborador->id,
        'colaborador_id' => $colaborador->colaborador_id,
        'tipo_movimiento' => TipoMovimientoLaboral::Baja->value,
        'fecha_movimiento' => now(),
    ]);

    $colaborador->delete();

    $reporte = $this->servicio->generar('bajas_por_mes', $this->rhAdmin, []);

    $mesActual = now()->translatedFormat('F Y');
    $fila = collect($reporte['filas'])->firstWhere(0, $mesActual);

    expect($fila)->not->toBeNull()
        ->and($fila[1])->toBe(1);
});

test('altas por mes usa el movimiento de alta (fecha_ingreso), no created_at', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subMonths(2)->toDateString()]);

    MovimientoLaboral::factory()->create([
        'user_id' => $colaborador->id,
        'colaborador_id' => $colaborador->colaborador_id,
        'tipo_movimiento' => TipoMovimientoLaboral::Alta->value,
        'fecha_movimiento' => now()->subMonths(2),
    ]);

    $reporte = $this->servicio->generar('altas_por_mes', $this->rhAdmin, []);

    $mesAlta = now()->subMonths(2)->translatedFormat('F Y');
    $mesCreacion = now()->translatedFormat('F Y');

    $filaAlta = collect($reporte['filas'])->firstWhere(0, $mesAlta);
    $filaCreacion = collect($reporte['filas'])->firstWhere(0, $mesCreacion);

    expect($filaAlta[1])->toBe(1);

    if ($mesCreacion !== $mesAlta) {
        expect($filaCreacion[1])->toBe(0);
    }
});

test('vacaciones solicitadas lee de solicitudes_internas, nunca de la tabla legacy', function () {
    $colaborador = User::factory()->create();

    $solicitudUnificada = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'colaborador_id' => $colaborador->colaborador_id,
        'tipo' => TipoSolicitudInterna::Vacaciones,
        'estado' => EstadoSolicitudInterna::Aprobada,
        'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(15)->toDateString(),
        'dias_solicitados' => 5,
    ]);

    SolicitudVacaciones::factory()->create(['user_id' => $colaborador->id]);

    $reporte = $this->servicio->generar('vacaciones_solicitudes', $this->rhAdmin, []);

    expect($reporte['filas'])->toHaveCount(1)
        ->and($reporte['filas'][0][3])->toBe(5);
});
