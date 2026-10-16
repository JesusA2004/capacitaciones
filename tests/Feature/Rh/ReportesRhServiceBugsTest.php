<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\MovimientoLaboral;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\Reportes\ReportesRhService;
use App\Services\Vacaciones\VacacionesService;
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

/*
| P1 — Reportes RH por colaborador (no por user_id): un colaborador activo
| sin cuenta de acceso debe aparecer en vacaciones/solicitudes igual que
| cualquier otro (antes se descartaba por no tener user, "Sin User → 0").
*/

test('vacaciones disponibles usa el expediente: un colaborador sin cuenta de acceso pero con antigüedad aparece con días > 0', function () {
    $persona = Colaborador::factory()->create([
        'name' => 'Sincuenta',
        'apellidos' => 'Con Antiguedad',
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
    ]);

    expect($persona->user)->toBeNull();

    $reporte = $this->servicio->generar('vacaciones_disponibles', $this->rhAdmin, []);
    $fila = collect($reporte['filas'])->first(fn ($f) => $f[0] === $persona->nombreCompleto());

    // [Colaborador, Sucursal, Generados, Usados, Disponibles]
    expect($fila)->not->toBeNull()
        ->and($fila[2])->toBeGreaterThan(0)
        ->and($fila[4])->toBeGreaterThan(0);
});

test('vacaciones disponibles de un colaborador con cuenta coincide con el saldo de su expediente', function () {
    $persona = Colaborador::factory()->create([
        'name' => 'Concuenta',
        'apellidos' => 'Alineada',
        'fecha_ingreso' => now()->subYears(2)->toDateString(),
    ]);
    User::factory()->create(['colaborador_id' => $persona->id]);

    $saldo = app(VacacionesService::class)->saldoColaborador($persona->fresh());

    $reporte = $this->servicio->generar('vacaciones_disponibles', $this->rhAdmin, []);
    $fila = collect($reporte['filas'])->first(fn ($f) => $f[0] === $persona->nombreCompleto());

    expect($fila)->not->toBeNull()
        ->and($fila[2])->toBe($saldo['dias_generados'])
        ->and($fila[4])->toBe($saldo['dias_disponibles']);
});

test('solicitudes internas cuentan por colaborador_id: una solicitud sin cuenta de acceso aparece', function () {
    $persona = Colaborador::factory()->create(['name' => 'Soli', 'apellidos' => 'Por Colaborador']);

    expect($persona->user)->toBeNull();

    SolicitudInterna::factory()->create([
        'user_id' => $this->rhAdmin->id,
        'colaborador_id' => $persona->id,
        'tipo' => TipoSolicitudInterna::General,
        'estado' => EstadoSolicitudInterna::Enviada,
    ]);

    $reporte = $this->servicio->generar('solicitudes_pendientes', $this->rhAdmin, []);
    $fila = collect($reporte['filas'])->first(fn ($f) => $f[1] === $persona->nombreCompleto());

    expect($fila)->not->toBeNull();
});

test('solicitudes internas legacy (solo user_id, sin colaborador_id) siguen apareciendo', function () {
    $colaborador = User::factory()->create();

    SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'colaborador_id' => null,
        'tipo' => TipoSolicitudInterna::General,
        'estado' => EstadoSolicitudInterna::Enviada,
    ]);

    $reporte = $this->servicio->generar('solicitudes_pendientes', $this->rhAdmin, []);
    $nombre = $colaborador->colaborador->nombreCompleto();
    $filas = collect($reporte['filas'])->filter(fn ($f) => $f[1] === $nombre);

    expect($filas)->toHaveCount(1);
});

test('una solicitud con colaborador_id y user_id del mismo colaborador no se cuenta dos veces', function () {
    $colaborador = User::factory()->create();

    SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'colaborador_id' => $colaborador->colaborador_id,
        'tipo' => TipoSolicitudInterna::General,
        'estado' => EstadoSolicitudInterna::Enviada,
    ]);

    $reporte = $this->servicio->generar('solicitudes_pendientes', $this->rhAdmin, []);
    $nombre = $colaborador->colaborador->nombreCompleto();
    $filas = collect($reporte['filas'])->filter(fn ($f) => $f[1] === $nombre);

    expect($filas)->toHaveCount(1);
});

test('el catálogo de reportes no tiene claves (value) duplicadas', function () {
    $values = collect($this->servicio->catalogo())
        ->flatMap(fn ($grupo) => collect($grupo['opciones'])->pluck('value'))
        ->all();

    expect($values)->toBe(array_values(array_unique($values)));
});

test('el reporte de movimientos de altas y bajas ya no se llama "Rotación"', function () {
    $catalogo = collect($this->servicio->catalogo())->flatMap(fn ($g) => $g['opciones']);

    expect($catalogo->firstWhere('value', 'rotacion'))->toBeNull()
        ->and($catalogo->firstWhere('value', 'movimientos_altas_bajas'))->not->toBeNull();

    $reporte = $this->servicio->generar('movimientos_altas_bajas', $this->rhAdmin, []);

    expect($reporte['titulo'])->not->toContain('Rotación')
        ->and($reporte['titulo'])->toContain('Movimientos');

    foreach ($this->servicio->catalogo() as $grupo) {
        foreach ($grupo['opciones'] as $opcion) {
            expect($opcion['label'])->not->toContain('Rotación');
        }
    }
});
