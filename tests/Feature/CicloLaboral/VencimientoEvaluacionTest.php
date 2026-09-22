<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');

    $this->jefeUsuario = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefeUsuario->colaborador_id, 'fecha_ingreso' => now()->subDays(20)]);
    $this->contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'fecha_inicio' => now()->subDays(20)->toDateString(),
        'fecha_fin' => now()->addDays(10)->toDateString(),
    ]);
});

test('el scheduler crea la evaluación, tareas y avisos 15 días antes, sin duplicar en ejecuciones repetidas', function () {
    // Un contrato que vence dentro de 40 días todavía no se avisa.
    ContratoLaboral::factory()->create(['fecha_fin' => now()->addDays(40)->toDateString()]);

    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect(EvaluacionPeriodoPrueba::query()->count())->toBe(1);

    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();
    expect($evaluacion->contrato_laboral_id)->toBe($this->contrato->id)
        ->and($evaluacion->evaluador_colaborador_id)->toBe($this->jefeUsuario->colaborador_id)
        ->and($evaluacion->estado)->toBe(EstadoEvaluacionPrueba::Pendiente)
        ->and($this->contrato->refresh()->aviso_vencimiento_en)->not->toBeNull();

    expect(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->where('asignado_user_id', $this->jefeUsuario->id)->count())->toBe(1)
        ->and(TareaRh::query()->where('tipo', TipoTarea::ContratoPorVencer->value)->count())->toBe(1);

    Notification::assertSentToTimes($this->jefeUsuario, PendienteRhNotification::class, 1);
    Notification::assertSentTo($this->rh, PendienteRhNotification::class);
});

test('el jefe inmediato captura la evaluación; un jefe ajeno no puede (403)', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Sanctum::actingAs(clUsuario('jefe_directo'));
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", [
        'criterios' => [['criterio' => 'Desempeño', 'calificacion' => 9]],
        'recomienda_renovar' => true,
    ])->assertForbidden();

    Sanctum::actingAs($this->jefeUsuario);
    $this->getJson('/api/v1/equipo/pendientes')->assertOk()->assertJsonCount(1, 'data.evaluaciones');

    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", [
        'criterios' => [
            ['criterio' => 'Desempeño', 'calificacion' => 9],
            ['criterio' => 'Puntualidad', 'calificacion' => 8],
        ],
        'recomienda_renovar' => true,
        'observaciones' => 'Buen desempeño.',
    ])->assertOk()
        ->assertJsonPath('data.estado', 'capturada')
        ->assertJsonPath('data.resultado', 'aprobado')
        ->assertJsonPath('data.calificacion', '8.50');

    // El jefe no autoriza su propia captura: eso es de RH/Dirección.
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => true])->assertForbidden();
});

test('si RH autoriza la renovación se crea el contrato indeterminado y su documento entra al flujo', function () {
    clPlantilla('contrato_indeterminado', ['requiere_firma_digital' => true, 'requiere_impresion' => true, 'requiere_firma_fisica' => true]);
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Sanctum::actingAs($this->jefeUsuario);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", ['criterios' => [['criterio' => 'General', 'calificacion' => 9]], 'recomienda_renovar' => true]);

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => true])->assertOk()->assertJsonPath('data.decision_renovar', true);

    $nuevo = ContratoLaboral::query()->where('contrato_anterior_id', $this->contrato->id)->firstOrFail();

    expect($this->contrato->refresh()->estado)->toBe(EstadoContratoLaboral::Renovado)
        ->and($nuevo->tipo->value)->toBe('indeterminado')
        ->and($nuevo->fecha_fin)->toBeNull()
        ->and($nuevo->documento?->estado_flujo?->value)->toBe('pendiente_firma_colaborador')
        ->and($this->colaborador->refresh()->tipo_contratacion?->value)->toBe('indeterminado');

    expect(TareaRh::query()->where('tipo', TipoTarea::ContratoPorVencer->value)->whereNull('resuelta_en')->count())->toBe(0);
});

test('si RH decide no renovar se inicia automáticamente el cierre laboral por no renovación', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Sanctum::actingAs($this->jefeUsuario);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", ['criterios' => [['criterio' => 'General', 'calificacion' => 4]], 'recomienda_renovar' => false]);

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => false, 'motivo_no_renovacion' => 'No aprobó el periodo de prueba.'])->assertOk();

    $cierre = CierreLaboral::query()->where('colaborador_id', $this->colaborador->id)->firstOrFail();

    expect($cierre->tipo_baja->value)->toBe('no_renovacion')
        ->and($cierre->evaluacion_id)->toBe($evaluacion->id)
        ->and($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($cierre->fecha_efectiva->toDateString())->toBe($this->contrato->fecha_fin?->toDateString())
        ->and(SolicitudInterna::query()->findOrFail($cierre->solicitud_interna_id)->tipo->value)->toBe('baja_colaborador');

    // Nunca se elimina al colaborador.
    expect(Colaborador::query()->whereKey($this->colaborador->id)->exists())->toBeTrue()
        ->and(User::query()->where('colaborador_id', $this->colaborador->id)->exists())->toBeFalse();
});
