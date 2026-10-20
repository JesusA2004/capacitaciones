<?php

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\DocumentosMaestros\ContextoDocumento;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| «Autorizar préstamo» desde la web (detalle de la solicitud). El préstamo
| lo AUTORIZA SOLO RH: el gerente (y regional / Dirección Comercial) solo
| da visto bueno. RH captura a mano los datos del «Contrato de crédito para
| trabajadores» (cargo, I.V.A., monto total, No. de cheque, primer y último
| pago): no se calculan, se imprimen tal cual. Web y API llaman al mismo
| PrestamoAutorizacionService; la app instalada (payload viejo) sigue igual.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->jefe = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefe->colaborador_id]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');

    foreach (['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion'] as $clave) {
        clPlantilla($clave, [
            'requiere_firma_digital' => true,
            'contenido_html' => '<p>{{nombre_completo}} · Cargo {{cargo_prestamo}} · IVA {{iva_prestamo}} · Total {{monto_total_prestamo}} · Cheque {{numero_cheque_prestamo}}</p>',
        ]);
    }
});

/** Solicitud de préstamo con el visto bueno del jefe ya dado (lista para RH). */
function apwSolicitudConVistoBueno(User $cuenta, User $jefe): int
{
    Sanctum::actingAs($cuenta);
    $id = test()->postJson('/api/v1/solicitudes', [
        'tipo' => 'prestamo',
        'motivo' => 'Gastos médicos',
        'monto_solicitado' => 10000,
    ])->assertCreated()->json('id');

    Sanctum::actingAs($jefe);
    test()->postJson("/api/v1/equipo/solicitudes/{$id}/visto-bueno", ['aprobado' => true, 'comentario' => 'De acuerdo'])->assertOk();

    return $id;
}

/** Lo que RH captura en el diálogo web «Autorizar préstamo». */
function apwPayload(array $cambios = []): array
{
    return [
        'monto_autorizado' => 8000,
        'plazo_autorizado' => 20,
        'periodicidad' => 'semanal',
        'cargo_sin_iva' => '150',
        'iva' => '24',
        'monto_total_con_iva' => '8174',
        'numero_cheque' => '0004512',
        'fecha_primer_descuento' => '2026-11-07',
        'fecha_ultimo_pago' => '2027-03-20',
        'observaciones' => 'Autorizado por RH.',
        ...$cambios,
    ];
}

test('RH autoriza el préstamo desde la web: guarda los datos capturados a mano y salen en el contrato', function () {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.prestamo.autorizar', $id), apwPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $prestamo = Prestamo::query()->where('solicitud_id', $id)->firstOrFail();

    // Tal cual lo capturó RH: nada se calcula ni se «cuadra».
    expect($prestamo->monto_original)->toBe('8000.00')
        ->and($prestamo->plazo)->toBe(20)
        ->and($prestamo->cargo_sin_iva)->toBe('150.00')
        ->and($prestamo->iva)->toBe('24.00')
        ->and($prestamo->monto_total_con_iva)->toBe('8174.00')
        ->and($prestamo->numero_cheque)->toBe('0004512')
        ->and($prestamo->fecha_primer_descuento?->toDateString())->toBe('2026-11-07')
        ->and($prestamo->fecha_ultimo_pago?->toDateString())->toBe('2027-03-20')
        ->and(SolicitudInterna::query()->findOrFail($id)->estado->value)->toBe('aprobada');

    // El contrato generado al autorizar lleva los datos capturados.
    $contrato = GeneratedDocument::query()->findOrFail($prestamo->contrato_documento_id);
    expect($contrato->payload['cargo_prestamo'])->toBe('$150.00')
        ->and($contrato->payload['iva_prestamo'])->toBe('$24.00')
        ->and($contrato->payload['monto_total_prestamo'])->toBe('$8,174.00')
        ->and($contrato->payload['numero_cheque_prestamo'])->toBe('0004512')
        // Ningún documento imprime el nombre de quien autoriza/captura.
        ->and(collect($contrato->payload)->contains($this->rh->nombreCompleto()))->toBeFalse();

    // Los masters (contrato de crédito sobre el PDF original) toman las
    // mismas casillas de DatosDocumentoService; el último pago es el capturado.
    $datos = app(DatosDocumentoService::class)->resolver(new ContextoDocumento($prestamo->colaborador, prestamo: $prestamo));
    expect($datos['cargo_prestamo'])->toBe('$150.00')
        ->and($datos['iva_prestamo'])->toBe('$24.00')
        ->and($datos['monto_total_prestamo'])->toBe('$8,174.00')
        ->and($datos['numero_cheque_prestamo'])->toBe('0004512')
        ->and($datos['fecha_primer_pago_corta'])->toBe('07/11/2026')
        ->and($datos['fecha_ultimo_pago_corta'])->toBe('20/03/2027');
});

test('la web exige los datos del contrato y valida montos numéricos no negativos', function () {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.prestamo.autorizar', $id), apwPayload([
            'cargo_sin_iva' => '',
            'iva' => '-5',
            'monto_total_con_iva' => 'mucho',
            'fecha_ultimo_pago' => '2026-10-01',
        ]))
        ->assertSessionHasErrors(['cargo_sin_iva', 'iva', 'monto_total_con_iva', 'fecha_ultimo_pago']);

    expect(Prestamo::query()->count())->toBe(0);
});

test('gerente, jefe directo y Dirección solo dan visto bueno: autorizar el préstamo les responde 403', function (string $rol) {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);

    $this->actingAs(clUsuario($rol))
        ->post(route('rh.solicitudes.prestamo.autorizar', $id), apwPayload())
        ->assertForbidden();

    Sanctum::actingAs(clUsuario($rol));
    $this->postJson("/api/v1/rh/solicitudes/{$id}/prestamo/autorizar", apwPayload())->assertForbidden();

    expect(Prestamo::query()->count())->toBe(0);
})->with(['gerente_sucursal', 'gerente', 'gerente_regional', 'director_comercial', 'direccion', 'jefe_directo']);

test('el jefe que dio el visto bueno tampoco autoriza desde la web', function () {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);

    $this->actingAs($this->jefe)
        ->post(route('rh.solicitudes.prestamo.autorizar', $id), apwPayload())
        ->assertForbidden();

    expect(Prestamo::query()->count())->toBe(0);
});

test('la vista previa con los datos capturados no crea el préstamo ni lo autoriza', function () {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);
    $datos = apwPayload();
    unset($datos['observaciones']);
    $documentosAntes = GeneratedDocument::query()->count();

    $documentos = $this->actingAs($this->rh)
        ->getJson(route('rh.documentos-proceso.previa.documentos', ['flujo' => 'prestamo', 'id' => $id, ...$datos]))
        ->assertOk()
        ->json('data');

    expect(array_column($documentos, 'clave'))->toBe(['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion'])
        // La URL del PDF lleva lo capturado (el contrato se ve con esos datos).
        ->and($documentos[0]['url'])->toContain('cargo_sin_iva=150')
        ->and($documentos[0]['url'])->toContain('numero_cheque=0004512');

    $respuesta = $this->get($documentos[0]['url'])->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf');

    expect(Prestamo::query()->count())->toBe(0)
        ->and(GeneratedDocument::query()->count())->toBe($documentosAntes)
        ->and(SolicitudInterna::query()->findOrFail($id)->estado->value)->not->toBe('aprobada');
});

test('la API con el payload de la app instalada (solo monto y plazo) sigue autorizando', function () {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);

    Sanctum::actingAs($this->rh);
    $prestamo = $this->postJson("/api/v1/rh/solicitudes/{$id}/prestamo/autorizar", [
        'monto_autorizado' => 8000,
        'plazo_autorizado' => 8,
    ])->assertCreated()->json('data');

    expect($prestamo['monto_autorizado'])->toBe('8000.00')
        ->and($prestamo['plazo_autorizado'])->toBe(8)
        ->and($prestamo['cargo_sin_iva'])->toBeNull()
        ->and($prestamo['numero_cheque'])->toBeNull();

    $modelo = Prestamo::query()->findOrFail($prestamo['id']);
    expect($modelo->iva)->toBeNull()
        ->and($modelo->monto_total_con_iva)->toBeNull()
        ->and($modelo->fecha_ultimo_pago)->toBeNull();
});

test('la API acepta los datos nuevos del contrato como opcionales', function () {
    $id = apwSolicitudConVistoBueno($this->cuenta, $this->jefe);

    Sanctum::actingAs($this->rh);
    $prestamo = $this->postJson("/api/v1/rh/solicitudes/{$id}/prestamo/autorizar", [
        'monto_autorizado' => 8000,
        'plazo_autorizado' => 8,
        'monto_total_con_iva' => 8500,
        'numero_cheque' => 'CH-77',
    ])->assertCreated()->json('data');

    expect($prestamo['monto_total_con_iva'])->toBe('8500.00')
        ->and($prestamo['numero_cheque'])->toBe('CH-77')
        ->and($prestamo['cargo_sin_iva'])->toBeNull();
});
