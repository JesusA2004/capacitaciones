<?php

use App\Models\SolicitudVacaciones;
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

test('un colaborador puede solicitar vacaciones dentro de su saldo disponible', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(3)]);
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('vacaciones.store'), [
            'fecha_inicio' => now()->addWeek()->toDateString(),
            'fecha_fin' => now()->addWeek()->addDays(4)->toDateString(),
            'dias_solicitados' => 5,
        ])
        ->assertSessionHasNoErrors();

    expect(SolicitudVacaciones::where('user_id', $colaborador->id)->exists())->toBeTrue();
});

test('no se puede solicitar mas dias de los disponibles', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('vacaciones.store'), [
            'fecha_inicio' => now()->addWeek()->toDateString(),
            'fecha_fin' => now()->addWeek()->addDays(30)->toDateString(),
            'dias_solicitados' => 30,
        ])
        ->assertSessionHasErrors('dias_solicitados');
});

test('un jefe_directo puede aprobar la solicitud de su subordinado pero no la de otro colaborador', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');

    $subordinado = User::factory()->create(['jefe_id' => $jefe->id, 'fecha_ingreso' => now()->subYears(2)]);
    $otro = User::factory()->create(['fecha_ingreso' => now()->subYears(2)]);

    $solicitudPropia = SolicitudVacaciones::factory()->create(['user_id' => $subordinado->id]);
    $solicitudAjena = SolicitudVacaciones::factory()->create(['user_id' => $otro->id]);

    $this->actingAs($jefe)
        ->post(route('rh.vacaciones.aprobar', $solicitudPropia))
        ->assertSessionHasNoErrors();

    expect($solicitudPropia->fresh()->estado->value)->toBe('aprobada');

    $this->actingAs($jefe)
        ->post(route('rh.vacaciones.aprobar', $solicitudAjena))
        ->assertForbidden();
});

test('un colaborador puede cancelar su propia solicitud pendiente', function () {
    $colaborador = User::factory()->create();
    $solicitud = SolicitudVacaciones::factory()->create(['user_id' => $colaborador->id]);

    $this->actingAs($colaborador)
        ->post(route('vacaciones.cancelar', $solicitud))
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh()->estado->value)->toBe('cancelada');
});
