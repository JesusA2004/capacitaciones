<?php

use App\Enums\EstadoUsuario;
use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('una solicitud de vacaciones respeta el saldo disponible del colaborador', function () {
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(3)]);
    $colaborador->assignRole('colaborador');

    // Antigüedad de 3 años -> 16 días generados (config/vacaciones.php).
    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'vacaciones',
            'motivo' => 'Vacaciones de fin de año.',
            'fecha_inicio' => now()->addDays(10)->toDateString(),
            'fecha_fin' => now()->addDays(15)->toDateString(),
            'dias_solicitados' => 5,
        ])
        ->assertSessionHasNoErrors();

    expect(SolicitudInterna::where('user_id', $colaborador->id)->first())
        ->tipo->value->toBe('vacaciones')
        ->dias_solicitados->toBe(5);

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'vacaciones',
            'motivo' => 'Más vacaciones.',
            'fecha_inicio' => now()->addDays(20)->toDateString(),
            'fecha_fin' => now()->addDays(40)->toDateString(),
            'dias_solicitados' => 100,
        ])
        ->assertSessionHasErrors('dias_solicitados');
});

test('una solicitud de préstamo interno requiere monto', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'prestamo',
            'motivo' => 'Emergencia familiar.',
        ])
        ->assertSessionHasErrors('monto_solicitado');

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'prestamo',
            'motivo' => 'Emergencia familiar.',
            'monto_solicitado' => 5000,
            'plazo_meses' => 6,
        ])
        ->assertSessionHasNoErrors();

    expect(SolicitudInterna::where('user_id', $colaborador->id)->first())
        ->monto_solicitado->toEqual('5000.00')
        ->plazo_meses->toBe(6);
});

test('solo quien tiene permiso de bajas puede crear una solicitud de baja de colaborador', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $objetivo = User::factory()->create();

    $this->actingAs($colaborador)
        ->post(route('solicitudes.store'), [
            'tipo' => 'baja_colaborador',
            'motivo' => 'Renuncia voluntaria.',
            'colaborador_objetivo_id' => $objetivo->id,
        ])
        ->assertForbidden();
});

test('rh_admin puede crear una solicitud de baja y al aprobarla se bloquea el acceso del colaborador', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $token = $colaborador->createToken('app-movil');

    $this->actingAs($rh)
        ->post(route('solicitudes.store'), [
            'tipo' => 'baja_colaborador',
            'motivo' => 'Renuncia voluntaria.',
            'colaborador_objetivo_id' => $colaborador->id,
        ])
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudInterna::where('tipo', 'baja_colaborador')->firstOrFail();
    expect($solicitud->colaborador_objetivo_id)->toBe($colaborador->id);
    expect($colaborador->fresh()->estatus)->toBe(EstadoUsuario::Activo);

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    $colaboradorTrasBaja = $colaborador->fresh();
    expect($colaboradorTrasBaja->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and($colaboradorTrasBaja->tokens()->count())->toBe(0);

    // El login web también queda bloqueado de inmediato.
    $this->post(route('login.store'), [
        'email' => $colaborador->email,
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});
