<?php

use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->direccion = clUsuario('direccion');
    $this->jefe = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefe->colaborador_id]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
});

function clSolicitarPrestamo(User $cuenta): int
{
    Sanctum::actingAs($cuenta);

    return test()->postJson('/api/v1/solicitudes', [
        'tipo' => 'prestamo',
        'motivo' => 'Gastos médicos',
        'monto_solicitado' => 10000,
        'plazo_meses' => 10,
    ])->assertCreated()->json('id');
}

test('préstamo: solicitud → visto bueno del jefe → autorización con monto/plazo → contrato y pagaré → resguardo', function () {
    clPlantilla('contrato_prestamo', ['requiere_firma_digital' => true]);
    clPlantilla('pagare', ['requiere_firma_digital' => true]);

    $solicitudId = clSolicitarPrestamo($this->cuenta);
    $solicitud = SolicitudInterna::query()->findOrFail($solicitudId);

    // El pendiente nace para el jefe inmediato (visto bueno).
    expect(TareaRh::query()->where('tipo', TipoTarea::PrestamoPendiente->value)->where('asignado_user_id', $this->jefe->id)->exists())->toBeTrue();

    // RH/Dirección no puede autorizar sin el visto bueno del jefe.
    Sanctum::actingAs($this->direccion);
    $this->postJson("/api/v1/rh/solicitudes/{$solicitudId}/prestamo/autorizar", ['monto_autorizado' => 8000, 'plazo_autorizado' => 8])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visto_bueno');

    // Solo el jefe real da el visto bueno (otro jefe recibe 403).
    Sanctum::actingAs(clUsuario('jefe_directo'));
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => true])->assertForbidden();

    Sanctum::actingAs($this->jefe);
    $this->getJson('/api/v1/equipo/pendientes')->assertOk()->assertJsonPath('data.solicitudes.0.requiere_visto_bueno', true);
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => true, 'comentario' => 'De acuerdo'])->assertOk();
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => true])->assertUnprocessable();

    // Dirección autoriza con monto y plazo distintos a lo solicitado.
    Sanctum::actingAs($this->direccion);
    $prestamo = $this->postJson("/api/v1/rh/solicitudes/{$solicitudId}/prestamo/autorizar", [
        'monto_autorizado' => 8000,
        'plazo_autorizado' => 8,
        'periodicidad' => 'quincenal',
    ])->assertCreated()->json('data');

    expect($prestamo['monto_solicitado'])->toBe('10000.00')
        ->and($prestamo['monto_autorizado'])->toBe('8000.00')
        ->and($prestamo['plazo_solicitado'])->toBe(10)
        ->and($prestamo['plazo_autorizado'])->toBe(8)
        ->and($prestamo['pago_programado'])->toBe('1000.00')
        ->and($prestamo['contrato']['estado'])->toBe('pendiente_firma_colaborador')
        ->and($prestamo['pagare']['estado'])->toBe('pendiente_firma_colaborador')
        ->and($solicitud->refresh()->estado->value)->toBe('aprobada');

    // El pagaré guarda el monto AUTORIZADO en su snapshot.
    expect(GeneratedDocument::query()->findOrFail($prestamo['pagare']['id'])->payload['monto_prestamo'])->toBe('$8,000.00');

    // Resguardo exige documentos firmados.
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/prestamos/{$prestamo['id']}/resguardar")->assertUnprocessable();

    Sanctum::actingAs($this->cuenta);
    $this->postJson("/api/v1/colaborador/documentos-laborales/{$prestamo['contrato']['id']}/firmar", ['acepto' => true])->assertOk();
    $this->postJson("/api/v1/colaborador/documentos-laborales/{$prestamo['pagare']['id']}/firmar", ['acepto' => true])->assertOk();
    $this->getJson('/api/v1/colaborador/prestamos')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/prestamos/{$prestamo['id']}/resguardar")->assertOk();
    expect(Prestamo::query()->findOrFail($prestamo['id'])->resguardado_en)->not->toBeNull();
});

test('si el jefe no da visto bueno la solicitud de préstamo queda rechazada', function () {
    $solicitudId = clSolicitarPrestamo($this->cuenta);

    Sanctum::actingAs($this->jefe);
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => false])->assertUnprocessable();
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => false, 'comentario' => 'No procede este mes.'])
        ->assertOk()
        ->assertJsonPath('data.estado_solicitud', 'rechazada');

    expect(Prestamo::query()->count())->toBe(0);
});

test('un colaborador no puede ver el préstamo de otro', function () {
    $prestamo = Prestamo::factory()->create();

    Sanctum::actingAs($this->cuenta);
    $this->getJson("/api/v1/colaborador/prestamos/{$prestamo->id}")->assertForbidden();
});
