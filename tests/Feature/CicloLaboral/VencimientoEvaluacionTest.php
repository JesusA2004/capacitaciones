<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\Contratos\ContratoLaboralService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');

    $this->jefeUsuario = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefeUsuario->colaborador_id, 'fecha_ingreso' => now()->subDays(20)]);
    // Autoevaluación: la persona evaluada tiene cuenta y la contesta ella.
    $this->colaboradorUsuario = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->colaboradorUsuario->assignRole('colaborador');
    $this->contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'fecha_inicio' => now()->subDays(20)->toDateString(),
        'fecha_fin' => now()->addDays(10)->toDateString(),
    ]);
});

/**
 * Captura oficial FO-RH-EPP-001 válida: 9 criterios con la misma
 * calificación (1-4) y las 5 preguntas filtro en "No".
 *
 * @return array{criterios: list<array{clave: string, calificacion: int}>, filtros: list<array{clave: string, respuesta: bool}>}
 */
function vepCapturaOficial(int $calificacion = 4): array
{
    return [
        'criterios' => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
            ->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => $calificacion])->all(),
        'filtros' => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
            ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => false])->all(),
    ];
}

test('el scheduler crea la evaluación, tareas y avisos 15 días antes, sin duplicar en ejecuciones repetidas', function () {
    // Un contrato que vence dentro de 40 días todavía no se avisa.
    ContratoLaboral::factory()->create(['fecha_fin' => now()->addDays(40)->toDateString()]);

    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect(EvaluacionPeriodoPrueba::query()->count())->toBe(1);

    // Autoevaluación: la contesta el propio colaborador, nunca su jefe.
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();
    expect($evaluacion->contrato_laboral_id)->toBe($this->contrato->id)
        ->and($evaluacion->evaluador_colaborador_id)->toBe($this->colaborador->id)
        ->and($evaluacion->estado)->toBe(EstadoEvaluacionPrueba::Pendiente)
        ->and($this->contrato->refresh()->aviso_vencimiento_en)->not->toBeNull();

    expect(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->where('asignado_user_id', $this->colaboradorUsuario->id)->where('accion', 'contestar_evaluacion')->count())->toBe(1)
        ->and(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->where('asignado_user_id', $this->jefeUsuario->id)->count())->toBe(0)
        ->and(TareaRh::query()->where('tipo', TipoTarea::ContratoPorVencer->value)->count())->toBe(1);

    Notification::assertSentToTimes($this->colaboradorUsuario, PendienteRhNotification::class, 1);
    Notification::assertNotSentTo($this->jefeUsuario, PendienteRhNotification::class);
    Notification::assertSentTo($this->rh, PendienteRhNotification::class);
});

test('solo el colaborador evaluado contesta su evaluación: su jefe, otro jefe y RH reciben 403', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    foreach ([clUsuario('jefe_directo'), $this->jefeUsuario, $this->rh, clUsuario('colaborador')] as $ajeno) {
        Sanctum::actingAs($ajeno);
        $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", vepCapturaOficial(4))->assertForbidden();
        $this->postJson("/api/v1/colaborador/evaluacion-periodo-prueba/{$evaluacion->id}/contestar", vepCapturaOficial(4))->assertForbidden();
    }

    // El jefe solo da seguimiento (sin poder contestarla).
    Sanctum::actingAs($this->jefeUsuario);
    $this->getJson('/api/v1/equipo/pendientes')->assertOk()
        ->assertJsonCount(1, 'data.evaluaciones')
        ->assertJsonPath('data.evaluaciones.0.puede_contestar', false);

    Sanctum::actingAs($this->colaboradorUsuario);
    $this->getJson('/api/v1/colaborador/evaluacion-periodo-prueba')->assertOk()
        ->assertJsonPath('data.id', $evaluacion->id)
        ->assertJsonPath('data.puede_contestar', true);

    $this->postJson("/api/v1/colaborador/evaluacion-periodo-prueba/{$evaluacion->id}/contestar", vepCapturaOficial(4))
        ->assertOk()
        ->assertJsonPath('data.estado', 'capturada')
        ->assertJsonPath('data.puede_contestar', false)
        ->assertJsonPath('data.calificacion', 36);

    expect($evaluacion->refresh()->capturada_por)->toBe($this->colaboradorUsuario->id)
        ->and($evaluacion->resultado?->value)->toBe('aprobado');

    // Ya enviada: no se puede volver a contestar.
    $this->postJson("/api/v1/colaborador/evaluacion-periodo-prueba/{$evaluacion->id}/contestar", vepCapturaOficial(3))->assertUnprocessable();

    // El colaborador no autoriza su propia evaluación: eso es de RH/Dirección.
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => true])->assertForbidden();
});

test('si RH autoriza la renovación se crea el contrato indeterminado y su documento entra al flujo', function () {
    clPlantilla('contrato_indeterminado', ['requiere_firma_digital' => true, 'requiere_impresion' => true, 'requiere_firma_fisica' => true]);
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Sanctum::actingAs($this->colaboradorUsuario);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", vepCapturaOficial(4));

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => true])->assertOk()->assertJsonPath('data.decision_renovar', true);

    $nuevo = ContratoLaboral::query()->where('contrato_anterior_id', $this->contrato->id)->firstOrFail();

    expect($this->contrato->refresh()->estado)->toBe(EstadoContratoLaboral::Renovado)
        ->and($nuevo->tipo->value)->toBe('indeterminado')
        ->and($nuevo->fecha_fin)->toBeNull()
        ->and($nuevo->documento?->estado_flujo?->value)->toBe('pendiente_firma_colaborador')
        ->and($this->colaborador->refresh()->tipo_contratacion?->value)->toBe('indeterminado');

    expect(TareaRh::query()->where('tipo', TipoTarea::ContratoPorVencer->value)->whereNull('resuelta_en')->count())->toBe(0);

    // «✓ Evaluación aprobada · Contrato indeterminado listo [Previsualizar] [Imprimir]».
    $this->actingAs($this->rh)->get(route('rh.evaluaciones.index', ['tab' => 'evaluadas']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('evaluaciones.data.0.contrato_renovacion_id', $nuevo->id)
            ->where('evaluaciones.data.0.documento_renovacion.id', $nuevo->generated_document_id)
            ->where('evaluaciones.data.0.documento_renovacion.url', route('rh.documentos-laborales.descargar', $nuevo->generated_document_id)));
});

test('si RH decide no renovar se inicia automáticamente el cierre laboral por no renovación', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Sanctum::actingAs($this->colaboradorUsuario);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", [
        ...vepCapturaOficial(2),
        'observaciones' => 'Calificación regular en todos los criterios: no alcanza el mínimo para renovar.',
    ]);

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => false, 'motivo_no_renovacion' => 'No aprobó el periodo de prueba.'])->assertOk();

    $cierre = CierreLaboral::query()->where('colaborador_id', $this->colaborador->id)->firstOrFail();

    expect($cierre->tipo_baja->value)->toBe('fin_contrato')
        ->and($cierre->evaluacion_id)->toBe($evaluacion->id)
        ->and($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($cierre->fecha_efectiva->toDateString())->toBe($this->contrato->fecha_fin?->toDateString())
        ->and(SolicitudInterna::query()->findOrFail($cierre->solicitud_interna_id)->tipo->value)->toBe('baja_colaborador');

    // Nunca se elimina al colaborador.
    expect(Colaborador::query()->whereKey($this->colaborador->id)->exists())->toBeTrue()
        ->and(User::query()->where('colaborador_id', $this->colaborador->id)->exists())->toBeTrue();
});

test('al cumplirse 15 DÍAS HÁBILES (no calendario) del vencimiento se avisa a la gerencia de sucursal y a RH, NUNCA a Regional, una sola vez', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-01 12:00:00', 'America/Mexico_City'));

    $sucursal = Sucursal::factory()->create();
    $otraSucursal = Sucursal::factory()->create();
    $gestorPuesto = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2]);
    $gerentePuesto = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    $regionalPuesto = Puesto::factory()->create(['nombre' => 'Gerente Regional Q1']);
    $rhPuesto = Puesto::factory()->create(['nombre' => 'Gerencia de Recursos Humanos']);

    $region = NodoComercial::factory()->create(['tipo' => 'region', 'nombre' => 'Q1', 'puesto_id' => $regionalPuesto->id]);
    NodoComercial::factory()->create(['tipo' => 'zona', 'parent_id' => $region->id, 'sucursal_id' => $sucursal->id]);

    $gerente = clUsuario('colaborador', ['puesto_id' => $gerentePuesto->id, 'sucursal_principal_id' => $sucursal->id]);
    $gerenteAjeno = clUsuario('colaborador', ['puesto_id' => $gerentePuesto->id, 'sucursal_principal_id' => $otraSucursal->id]);
    // Regional de LA MISMA región de esta sucursal: aunque esté perfectamente
    // ligado, nunca debe recibir el aviso automático de simple vencimiento
    // (solo interviene si el gerente "Solicita intervención" explícitamente).
    $regional = clUsuario('colaborador', ['puesto_id' => $regionalPuesto->id, 'sucursal_principal_id' => $otraSucursal->id]);
    $gerenteRh = clUsuario('colaborador', ['puesto_id' => $rhPuesto->id]);

    $gestor = Colaborador::factory()->create(['puesto_id' => $gestorPuesto->id, 'sucursal_principal_id' => $sucursal->id, 'jefe_id' => $gerente->colaborador_id]);

    $ahora = now();
    $calendario = app(CalendarioLaboralService::class);
    // Fecha de fin fijada exactamente a 15 días HÁBILES de "ahora": es el
    // límite exacto que debe disparar el aviso (inclusive).
    $fin = $calendario->sumarDiasHabiles($ahora, 15);
    $contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $gestor->id,
        'fecha_inicio' => $ahora->copy()->subMonths(2)->toDateString(),
        'fecha_fin' => $fin->toDateString(),
    ]);

    // Un DÍA HÁBIL antes de cumplir los 15 días hábiles todavía no se avisa
    // (si se contara en calendario, un viernes→lunes se adelantaría mal).
    // now() es CarbonImmutable: subDay() NUNCA muta in-place, hay que reasignar.
    $diaHabilAntes = $ahora;
    do {
        $diaHabilAntes = $diaHabilAntes->subDay();
    } while ($diaHabilAntes->isWeekend());

    Carbon\Carbon::setTestNow($diaHabilAntes);
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    expect($contrato->refresh()->aviso_vencimiento_en)->toBeNull();

    Carbon\Carbon::setTestNow($ahora);
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect($contrato->refresh()->aviso_vencimiento_en)->not->toBeNull();

    $avisoContrato = fn (PendienteRhNotification $n, array $canales, User $destino) => $n->toDatabase($destino)['tipo'] === 'contrato_por_vencer';

    // El gerente (jefe directo) ya no evalúa: recibe solo el aviso de renovación.
    Notification::assertSentToTimes($gerente, PendienteRhNotification::class, 1);
    Notification::assertSentToTimes($gerenteRh, PendienteRhNotification::class, 1);
    Notification::assertSentTo($gerenteRh, PendienteRhNotification::class, $avisoContrato);
    Notification::assertNotSentTo($gerenteAjeno, PendienteRhNotification::class);
    Notification::assertNotSentTo($regional, PendienteRhNotification::class);

    Carbon\Carbon::setTestNow();
});

test('el gerente de sucursal recibe el aviso de renovación y el colaborador el de contestar su evaluación', function () {
    $sucursal = Sucursal::factory()->create();
    $gerentePuesto = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    $gerente = clUsuario('colaborador', ['puesto_id' => $gerentePuesto->id, 'sucursal_principal_id' => $sucursal->id]);
    $this->colaborador->update(['sucursal_principal_id' => $sucursal->id]);

    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    Notification::assertSentToTimes($gerente, PendienteRhNotification::class, 1);
    // El jefe directo ya no recibe «evalúa»: la contesta el colaborador.
    Notification::assertNotSentTo($this->jefeUsuario, PendienteRhNotification::class);
    Notification::assertSentToTimes($this->colaboradorUsuario, PendienteRhNotification::class, 1);
});

test('aprobar dos veces no duplica el contrato indeterminado (idempotente)', function () {
    clPlantilla('contrato_indeterminado');
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Sanctum::actingAs($this->colaboradorUsuario);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", vepCapturaOficial(4));

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => true])->assertOk();
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/autorizar", ['renovar' => true])->assertUnprocessable();

    expect(ContratoLaboral::query()->where('contrato_anterior_id', $this->contrato->id)->count())->toBe(1)
        ->and($evaluacion->refresh()->contrato_renovacion_id)->not->toBeNull();
});

test('la duración de la capacitación sale del PUESTO (sin respaldo global)', function () {
    $contratos = app(ContratoLaboralService::class);
    $subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'meses_periodo_prueba' => 3]);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2]);

    expect($contratos->fechaFinPeriodoPrueba($gestor->id, CarbonImmutable::parse('2026-10-01'))->toDateString())->toBe('2026-11-30')
        ->and($contratos->fechaFinPeriodoPrueba($subgerente->id, CarbonImmutable::parse('2026-01-31'))->toDateString())->toBe('2026-04-29');
});

test('un puesto sin duración configurada bloquea la contratación con un mensaje claro', function () {
    $sinDuracion = Puesto::factory()->sinDuracionCapacitacion()->create(['nombre' => 'Analista']);

    expect(fn () => app(ContratoLaboralService::class)->fechaFinPeriodoPrueba($sinDuracion->id, now()))
        ->toThrow(ValidationException::class, 'Configura la duración de capacitación inicial para este puesto («Analista»)');
});
