<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\CicloLaboral\ReingresoService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Compensacion\CompensacionService;
use App\Services\Finiquitos\FiniquitoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| P0-1 / P2-18: CompensacionService es el ÚNICO escritor de compensación.
| Todo cambio de sueldo (alta, edición de datos laborales, reingreso) deja
| `sueldo_mensual` y `salario_diario` sincronizados (mensual = diario×30) y
| un asiento en historial_salarios. Así la Nómina (lee sueldo_mensual) y el
| Finiquito (lee salario_diario vía diarioDe) cobran el MISMO monto.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->compensacion = app(CompensacionService::class);
});

test('editar el sueldo por ExpedienteController sincroniza salario_diario, deja asiento en historial y coincide con lo que leen Nómina y Finiquito', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create();

    $usuario = User::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id]);
    $usuario->assignRole('colaborador');
    // Punto de partida ya sincronizado (300 × 30 = 9000).
    $usuario->colaborador->update(['salario_diario' => 300, 'sueldo_mensual' => 9000]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->put(route('rh.expedientes.datos-laborales.update', $usuario->colaborador_id), [
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $puesto->id,
            'sueldo_mensual' => 12000,
        ])
        ->assertSessionHasNoErrors();

    $colaborador = $usuario->colaborador->fresh();

    expect((float) $colaborador->salario_diario)->toBe(400.0)
        ->and((float) $colaborador->sueldo_mensual)->toBe(12000.0)
        ->and($this->compensacion->diarioDe($colaborador))->toBe(400.0);

    // Asiento append-only del cambio (fuente del flujo de datos laborales).
    $historial = HistorialSalario::query()->where('colaborador_id', $colaborador->id)->where('fuente', 'datos_laborales')->latest('id')->first();
    expect($historial)->not->toBeNull()
        ->and((float) $historial->salario_diario_anterior)->toBe(300.0)
        ->and((float) $historial->salario_diario_nuevo)->toBe(400.0);

    // Movimiento de cambio de sueldo (había diario anterior).
    expect(MovimientoLaboral::query()->where('colaborador_id', $colaborador->id)->where('tipo_movimiento', 'cambio_sueldo')->exists())->toBeTrue();

    // Fuente canónica única: el mensual que lee la Nómina == el mensual que
    // calcula el Finiquito desde el diario. Misma persona, mismo monto.
    $mensualNomina = (float) $colaborador->sueldo_mensual;
    $mensualFiniquito = app(FiniquitoService::class)->sueldoMensualSugerido($colaborador);
    expect($mensualNomina)->toBe($mensualFiniquito);
});

test('un alta normal registra el salario por CompensacionService: salario_diario sincronizado y asiento inicial en historial (sin movimiento de cambio)', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create(['meses_periodo_prueba' => 2]);

    $actor = clUsuario('rh_admin');

    $colaborador = app(AltaColaboradorService::class)->registrar([
        'name' => 'Persona de Prueba',
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'sueldo_mensual' => 9000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => TipoContratacion::CapacitacionInicial->value,
        'crear_acceso' => true,
    ], $actor);

    $colaborador = $colaborador->fresh();

    expect((float) $colaborador->salario_diario)->toBe(300.0)
        ->and((float) $colaborador->sueldo_mensual)->toBe(9000.0)
        ->and($this->compensacion->diarioDe($colaborador))->toBe(300.0);

    // Asiento inicial de salario (P2-18: antes quedaba salario_diario null).
    expect(HistorialSalario::query()->where('colaborador_id', $colaborador->id)->where('fuente', 'alta_colaborador')->count())->toBe(1);

    // No hay "cambio de sueldo": es el salario inicial (sin diario anterior).
    expect(MovimientoLaboral::query()->where('colaborador_id', $colaborador->id)->where('tipo_movimiento', 'cambio_sueldo')->exists())->toBeFalse();
});

test('un reingreso con sueldo distinto lo escribe por CompensacionService: salario_diario sincronizado y asiento en historial', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create(['meses_periodo_prueba' => 2]);

    // Excolaborador (baja), con salario anterior ya sincronizado.
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'estatus' => EstadoUsuario::Inactivo,
        'estado_alta' => EstadoAltaColaborador::Baja,
        'salario_diario' => 300,
        'sueldo_mensual' => 9000,
    ]);

    $rh = clUsuario('rh_admin');
    $rh2 = clUsuario('rh_admin');

    $reingresos = app(ReingresoService::class);
    $reingreso = $reingresos->solicitar($colaborador, ['motivo' => 'Buen desempeño previo.', 'sueldo_mensual' => 12000], $rh);
    $reingresos->decidir($reingreso, $rh2, true, 'Viable.');

    $colaborador = $colaborador->fresh();

    expect((float) $colaborador->salario_diario)->toBe(400.0)
        ->and((float) $colaborador->sueldo_mensual)->toBe(12000.0)
        ->and($this->compensacion->diarioDe($colaborador))->toBe(400.0);

    $historial = HistorialSalario::query()->where('colaborador_id', $colaborador->id)->where('fuente', 'reingreso')->latest('id')->first();
    expect($historial)->not->toBeNull()
        ->and((float) $historial->salario_diario_nuevo)->toBe(400.0);
});
