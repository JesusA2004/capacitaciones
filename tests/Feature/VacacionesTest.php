<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Vacaciones\VacacionesService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('la tabla legal de vacaciones calcula los dias correctos por antiguedad', function () {
    $servicio = app(VacacionesService::class);

    expect($servicio->diasPorAntiguedad(0))->toBe(0)
        ->and($servicio->diasPorAntiguedad(1))->toBe(12)
        ->and($servicio->diasPorAntiguedad(5))->toBe(20)
        ->and($servicio->diasPorAntiguedad(6))->toBe(22)
        ->and($servicio->diasPorAntiguedad(10))->toBe(22)
        ->and($servicio->diasPorAntiguedad(11))->toBe(24);
});

/**
 * Las vacaciones se solicitan desde el módulo unificado de Solicitudes
 * (tipo `vacaciones`, ver docs/SOLICITUDES_UNIFICADAS.md) — el módulo web
 * standalone `/vacaciones` se retiró porque enviaba a una tabla que la
 * bandeja de RH nunca revisaba.
 */
test('un colaborador puede solicitar vacaciones dentro de su saldo disponible', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(3)]);
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'vacaciones',
            'motivo' => 'Vacaciones familiares',
            'fecha_inicio' => now()->addWeek()->toDateString(),
            'fecha_fin' => now()->addWeek()->addDays(4)->toDateString(),
            'dias_solicitados' => 5,
        ])
        ->assertSessionHasNoErrors();

    expect(SolicitudInterna::where('user_id', $colaborador->id)->where('tipo', 'vacaciones')->exists())->toBeTrue();
});

test('no se puede solicitar mas dias de los disponibles', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'vacaciones',
            'motivo' => 'Vacaciones familiares',
            'fecha_inicio' => now()->addWeek()->toDateString(),
            'fecha_fin' => now()->addWeek()->addDays(30)->toDateString(),
            'dias_solicitados' => 30,
        ])
        ->assertSessionHasErrors('dias_solicitados');
});

test('un jefe_directo puede aprobar la solicitud de vacaciones de su subordinado pero no la de otro colaborador', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');

    $subordinado = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id, 'fecha_ingreso' => now()->subYears(2)])]);
    $otro = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['fecha_ingreso' => now()->subYears(2)])]);

    $solicitudPropia = SolicitudInterna::factory()->create(['tipo' => 'vacaciones', 'user_id' => $subordinado->id, 'colaborador_id' => $subordinado->colaborador_id]);
    $solicitudAjena = SolicitudInterna::factory()->create(['tipo' => 'vacaciones', 'user_id' => $otro->id, 'colaborador_id' => $otro->colaborador_id]);

    $this->actingAs($jefe)
        ->post(route('rh.solicitudes.aprobar', $solicitudPropia))
        ->assertSessionHasNoErrors();

    expect($solicitudPropia->fresh()->estado->value)->toBe('aprobada');

    $this->actingAs($jefe)
        ->post(route('rh.solicitudes.aprobar', $solicitudAjena))
        ->assertForbidden();
});

test('un colaborador puede cancelar su propia solicitud de vacaciones pendiente', function () {
    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['tipo' => 'vacaciones', 'user_id' => $colaborador->id, 'estado' => 'enviada']);

    $this->actingAs($colaborador)
        ->post(route('solicitudes.cancelar', $solicitud))
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh()->estado->value)->toBe('cancelada');
});
