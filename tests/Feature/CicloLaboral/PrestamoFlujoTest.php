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
    ])->assertCreated()->json('id');
}

test('préstamo: solicitud → visto bueno del jefe → autorización con monto/plazo → contrato y pagaré → resguardo', function () {
    // Formatos de préstamo de Jurídico: contrato de crédito, pagaré y
    // consentimiento de retención.
    foreach (['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion'] as $clave) {
        clPlantilla($clave, ['requiere_firma_digital' => true]);
    }

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
        // El colaborador no propone plazo: lo decide RH al autorizar.
        ->and($prestamo['plazo_solicitado'])->toBeNull()
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

test('el boton generico "Aprobar" nunca autoriza un prestamo: solo "Autorizar prestamo" puede', function () {
    $solicitudId = clSolicitarPrestamo($this->cuenta);

    Sanctum::actingAs($this->jefe);
    $this->postJson("/api/v1/equipo/solicitudes/{$solicitudId}/visto-bueno", ['aprobado' => true, 'comentario' => 'De acuerdo'])->assertOk();

    // Con visto bueno ya dado, el endpoint GENÉRICO de aprobar (el mismo
    // que usan el resto de tipos de solicitud) no debe crear ningún
    // Prestamo con el monto/plazo sin revisar — bug real encontrado en
    // auditoría: sin este guardado, PrestamoService::crearDesdeSolicitud()
    // caía en sus defaults (monto tal cual lo pidió el colaborador, plazo
    // inventado) como si RH sí hubiera autorizado algo.
    Sanctum::actingAs($this->direccion);
    $this->postJson("/api/v1/rh/solicitudes/{$solicitudId}/aprobar", ['comentario' => 'ok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto_autorizado');

    expect(Prestamo::query()->count())->toBe(0);
    expect(SolicitudInterna::query()->findOrFail($solicitudId)->estado->value)->not->toBe('aprobada');

    // El camino correcto sigue funcionando igual que antes.
    $this->postJson("/api/v1/rh/solicitudes/{$solicitudId}/prestamo/autorizar", [
        'monto_autorizado' => 8000,
        'plazo_autorizado' => 8,
    ])->assertCreated();

    expect(Prestamo::query()->count())->toBe(1);
});

test('un colaborador no puede ver el préstamo de otro', function () {
    $prestamo = Prestamo::factory()->create();

    Sanctum::actingAs($this->cuenta);
    $this->getJson("/api/v1/colaborador/prestamos/{$prestamo->id}")->assertForbidden();
});
