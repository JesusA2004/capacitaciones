<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EmployeeDocument;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
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
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'fecha_ingreso' => now()->subYear(),
        'sueldo_mensual' => 12000,
        'estado_alta' => EstadoAltaColaborador::Activo->value,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
    $this->cuenta->createToken('app');
    $this->contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id, 'tipo' => 'indeterminado', 'fecha_fin' => null]);
});

test('cierre laboral completo: renuncia → aviso → finiquito modular → firma → pago → baja → expediente cerrado', function () {
    Sanctum::actingAs($this->rh);

    $cierreId = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/cierres", [
        'tipo_baja' => 'renuncia',
        'motivo' => 'Renuncia voluntaria por cambio de ciudad.',
        'fecha_efectiva' => now()->addDays(5)->toDateString(),
    ])->assertCreated()->assertJsonPath('data.estado', 'iniciado')->json('data.id');

    // No se permite un segundo cierre en proceso.
    $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/cierres", [
        'tipo_baja' => 'renuncia', 'motivo' => 'Duplicado', 'fecha_efectiva' => now()->toDateString(),
    ])->assertUnprocessable();

    $this->post("/api/v1/rh/cierres/{$cierreId}/aviso", ['archivo' => clArchivoPdf('renuncia.pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.estado', 'aviso_registrado');

    // Ejecutar la baja antes del finiquito no está permitido.
    $this->postJson("/api/v1/rh/cierres/{$cierreId}/ejecutar-baja")->assertUnprocessable();

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/calcular", ['sueldo_mensual' => 12000, 'sueldo_pendiente' => 2000])
        ->assertOk()->assertJsonPath('data.estado', 'finiquito_en_proceso');

    $finiquito = FiniquitoCalculo::query()->firstOrFail();
    $netoAutomatico = (float) $finiquito->neto;

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/conceptos", ['tipo' => 'percepcion', 'concepto' => 'Bono de productividad', 'importe' => 1500])->assertOk();
    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/conceptos", ['tipo' => 'deduccion', 'concepto' => 'Uniforme no devuelto', 'cantidad' => 2, 'importe' => 500])->assertOk();

    $finiquito->refresh();
    expect((float) $finiquito->neto)->toBe(round($netoAutomatico + 1500 - 500, 2))
        ->and((float) $finiquito->total_ajustado)->toBe((float) $finiquito->neto)
        ->and((float) $finiquito->total_percepciones - (float) $finiquito->total_deducciones)->toBe((float) $finiquito->neto);

    $desglose = $this->getJson("/api/v1/rh/cierres/{$cierreId}")->json('data.finiquito.desglose');
    expect(collect($desglose)->where('origen', 'manual')->pluck('concepto')->all())->toBe(['Bono de productividad', 'Uniforme no devuelto']);

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/revisar")->assertOk();
    $documentoId = $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/documento")->assertOk()->json('documento_id');

    // El PDF del finiquito queda en el expediente (BajaFiniquito) con snapshot de conceptos.
    $documento = GeneratedDocument::query()->findOrFail($documentoId);
    expect($documento->path)->toContain('/BajaFiniquito/')
        ->and($finiquito->refresh()->snapshot['conceptos'])->toHaveCount(count($desglose));

    // Sin firma no hay pago.
    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/pago", ['referencia_pago' => 'TRF-001'])->assertUnprocessable();

    $this->post("/api/v1/rh/cierres/{$cierreId}/finiquito/firmado", ['archivo' => clArchivoPdf('finiquito-firmado.pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.estado', 'finiquito_firmado');

    // Firmado: ya no se pueden alterar sus conceptos.
    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/conceptos", ['tipo' => 'percepcion', 'concepto' => 'Otro', 'importe' => 10])->assertUnprocessable();

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/finiquito/pago", ['referencia_pago' => 'TRF-001'])->assertOk()->assertJsonPath('data.estado', 'pagado');
    expect($finiquito->refresh()->estado)->toBe(EstadoFiniquito::Pagado);

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/ejecutar-baja")->assertOk()->assertJsonPath('data.estado', 'baja_ejecutada');

    $this->colaborador->refresh();
    expect($this->colaborador->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and($this->colaborador->estado_alta)->toBe(EstadoAltaColaborador::Baja)
        ->and($this->colaborador->fecha_baja?->toDateString())->toBe(now()->addDays(5)->toDateString())
        ->and($this->contrato->refresh()->estado)->toBe(EstadoContratoLaboral::Terminado)
        ->and($this->cuenta->tokens()->count())->toBe(0);

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/cerrar-expediente")->assertOk()->assertJsonPath('data.estado', 'expediente_cerrado');

    // El colaborador y su historial se conservan (nunca se borra).
    expect(Colaborador::withTrashed()->whereKey($this->colaborador->id)->exists())->toBeTrue()
        ->and($this->colaborador->refresh()->expediente_cerrado_en)->not->toBeNull()
        ->and(EmployeeDocument::query()->where('colaborador_id', $this->colaborador->id)->where('status', 'aprobado')->exists())->toBeTrue();
});

test('sin permiso cierres.gestionar no se puede iniciar un cierre laboral', function () {
    Sanctum::actingAs(clUsuario('jefe_directo'));

    $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/cierres", [
        'tipo_baja' => 'renuncia', 'motivo' => 'x', 'fecha_efectiva' => now()->toDateString(),
    ])->assertForbidden();
});
