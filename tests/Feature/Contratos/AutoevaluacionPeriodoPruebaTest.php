<?php

use App\Enums\EstadoAprobacion;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EtapaAprobacion;
use App\Enums\TipoTarea;
use App\Models\Aprobacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Contratos\EvaluacionPeriodoPruebaPdfService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\VencimientoContratosService;
use App\Services\Mobile\MobileBootstrapService;
use App\Services\Notificaciones\DestinoNotificacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| La evaluación de capacitación inicial / periodo de prueba (FO-RH-EPP-001)
| es una AUTOEVALUACIÓN: la contesta SOLO el colaborador evaluado desde «Mi
| espacio» (web) o la app. RH la asigna, ve las respuestas, imprime el PDF
| y decide (autorizar / devolver al colaborador). Ni RH, ni su jefe, ni
| super_admin la contestan.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    clPlantilla('contrato_indeterminado');

    $this->rh = clUsuario('rh_admin');
    $this->jefe = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefe->colaborador_id, 'estatus' => 'activo', 'estado_alta' => null]);
    $this->usuario = clUsuarioDe($this->colaborador);
    $this->contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'fecha_inicio' => now()->subDays(50)->toDateString(),
        'fecha_fin' => now()->addDays(10)->toDateString(),
    ]);

    // RH la asigna desde el tablero (mismo camino que «Asignar evaluación»).
    $this->evaluacion = app(VencimientoContratosService::class)->asignarAhora($this->colaborador, $this->rh);
});

/**
 * @return array<string, mixed>
 */
function autoCaptura(int $calificacion = 4): array
{
    return [
        'criterios' => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
            ->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => $calificacion])->all(),
        'filtros' => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
            ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => false])->all(),
        'observaciones' => 'Me siento bien preparado.',
    ];
}

test('al asignarla, la tarea y el aviso son para el colaborador evaluado, con enlace a Mi espacio', function () {
    expect($this->evaluacion->evaluador_colaborador_id)->toBe($this->colaborador->id);

    $tarea = TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->whereNull('resuelta_en')->sole();
    expect($tarea->asignado_user_id)->toBe($this->usuario->id)
        ->and($tarea->accion)->toBe(EvaluacionPeriodoPruebaService::ACCION_CONTESTAR);

    Notification::assertSentTo($this->usuario, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->usuario)['tipo'] === 'evaluacion_pendiente');
    Notification::assertNotSentTo($this->jefe, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->jefe)['tipo'] === 'evaluacion_pendiente');

    // El aviso lleva al colaborador a su pantalla; a RH, al expediente.
    $aviso = new DatabaseNotification(['id' => (string) Str::uuid(), 'data' => ['type' => 'evaluacion_pendiente', 'related_type' => 'EvaluacionPeriodoPrueba', 'resource_id' => $this->evaluacion->id]]);
    $destinos = app(DestinoNotificacionService::class);
    expect($destinos->resolver($aviso, $this->usuario)['url'])->toBe(route('portal.evaluacion-periodo-prueba', [], false))
        ->and($destinos->resolver($aviso, $this->rh)['url'])->not->toBe(route('portal.evaluacion-periodo-prueba', [], false));

    // Aparece como pendiente en Mi espacio / Mis pendientes (web y app).
    $pendientes = app(CicloLaboralService::class)->misPendientes($this->colaborador, $this->usuario)['pendientes'];
    expect(collect($pendientes)->firstWhere('clave', 'evaluacion_periodo_prueba')['accion']['href'] ?? null)->toBe(route('portal.evaluacion-periodo-prueba'));
});

test('el colaborador ve y contesta su evaluación en Mi espacio; después queda en solo lectura', function () {
    $this->actingAs($this->usuario)
        ->get(route('portal.evaluacion-periodo-prueba'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Portal/EvaluacionPeriodoPrueba')
            ->where('evaluacion.id', $this->evaluacion->id)
            ->where('evaluacion.puede_contestar', true)
            ->has('evaluacion.formulario.criterios', count(config('contratos.evaluacion_periodo_prueba_v2.criterios'))));

    $this->actingAs($this->usuario)
        ->post(route('portal.evaluacion-periodo-prueba.contestar', $this->evaluacion), autoCaptura(4))
        ->assertRedirect(route('portal.evaluacion-periodo-prueba'))
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'success');

    $evaluacion = $this->evaluacion->refresh();
    expect($evaluacion->estado)->toBe(EstadoEvaluacionPrueba::Capturada)
        ->and($evaluacion->capturada_por)->toBe($this->usuario->id)
        ->and($evaluacion->recomienda_renovar)->toBeTrue();

    // No es preautorización: «no aplica» y queda solo la decisión de RH.
    $aprobaciones = Aprobacion::query()->where('aprobable_type', $evaluacion->getMorphClass())->where('aprobable_id', $evaluacion->id);
    expect((clone $aprobaciones)->where('etapa', EtapaAprobacion::Preautorizacion->value)->value('estado'))->toBe(EstadoAprobacion::Omitido)
        ->and((clone $aprobaciones)->where('etapa', EtapaAprobacion::AutorizacionRh->value)->value('estado'))->toBe(EstadoAprobacion::Pendiente)
        ->and(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->whereNull('resuelta_en')->count())->toBe(0)
        ->and(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPorAutorizar->value)->whereNull('resuelta_en')->count())->toBe(1);

    Notification::assertSentTo($this->rh, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->rh)['tipo'] === 'evaluacion_capturada');

    // Solo lectura: ya no puede volver a contestarla.
    $this->actingAs($this->usuario)
        ->get(route('portal.evaluacion-periodo-prueba'))
        ->assertInertia(fn (Assert $p) => $p->where('evaluacion.puede_contestar', false)->where('evaluacion.estado', 'capturada'));
    $this->actingAs($this->usuario)
        ->post(route('portal.evaluacion-periodo-prueba.contestar', $this->evaluacion), autoCaptura(3))
        ->assertSessionHasErrors('estado');
});

test('nadie más contesta la evaluación: su jefe, RH, super_admin y otro colaborador reciben 403', function () {
    $superAdmin = clUsuario('super_admin');

    foreach ([$this->jefe, $this->rh, $superAdmin, clUsuario('colaborador')] as $ajeno) {
        $this->actingAs($ajeno)
            ->post(route('portal.evaluacion-periodo-prueba.contestar', $this->evaluacion), autoCaptura(4))
            ->assertForbidden();

        Sanctum::actingAs($ajeno);
        $this->postJson("/api/v1/evaluaciones/{$this->evaluacion->id}/capturar", autoCaptura(4))->assertForbidden();
        $this->postJson("/api/v1/colaborador/evaluacion-periodo-prueba/{$this->evaluacion->id}/contestar", autoCaptura(4))->assertForbidden();
    }

    // Defensa en profundidad: el service tampoco lo permite.
    expect(fn () => app(EvaluacionPeriodoPruebaService::class)->contestar($this->evaluacion, $superAdmin, autoCaptura(4)))
        ->toThrow(AuthorizationException::class);

    // La ruta de captura de RH ya no existe.
    expect(Route::has('rh.evaluaciones.capturar'))->toBeFalse()
        ->and($this->evaluacion->refresh()->estado)->toBe(EstadoEvaluacionPrueba::Pendiente);

    // El tablero de RH no ofrece contestarla.
    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index', ['tab' => 'en_proceso']))
        ->assertInertia(fn (Assert $p) => $p
            ->where('evaluaciones.data.0.id', $this->evaluacion->id)
            ->missing('evaluaciones.data.0.puede_capturar')
            ->where('conteos.esperando_colaborador', 1));
});

test('RH ve las respuestas y el PDF; devolver regresa la evaluación al colaborador, que la corrige, y RH autoriza', function () {
    app(EvaluacionPeriodoPruebaService::class)->contestar($this->evaluacion, $this->usuario, autoCaptura(3));

    $this->actingAs($this->rh)
        ->get(route('rh.evaluaciones.index', ['tab' => 'evaluadas']))
        ->assertInertia(fn (Assert $p) => $p
            ->where('evaluaciones.data.0.respuestas.autoevaluacion', true)
            ->where('evaluaciones.data.0.respuestas.capturada_por', $this->usuario->nombreCompleto())
            ->where('evaluaciones.data.0.respuestas.observaciones', 'Me siento bien preparado.')
            ->where('evaluaciones.data.0.pdf_url', route('rh.evaluaciones.pdf', $this->evaluacion->id)));

    $html = app(EvaluacionPeriodoPruebaPdfService::class)->html($this->evaluacion->refresh());
    expect($html)->toContain('CONTESTÓ')->toContain('COMENTARIOS DEL COLABORADOR')->toContain('Me siento bien preparado.');
    $this->actingAs($this->rh)->get(route('rh.evaluaciones.pdf', $this->evaluacion->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Devolver: vuelve al colaborador con el motivo y sus respuestas precargadas.
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$this->evaluacion->id}/devolver", ['motivo' => 'Revisa tu calificación de puntualidad.'])
        ->assertOk()
        ->assertJsonPath('data.estado', 'devuelta');

    expect(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->whereNull('resuelta_en')->value('asignado_user_id'))->toBe($this->usuario->id);
    Notification::assertSentTo($this->usuario, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->usuario)['tipo'] === 'evaluacion_devuelta');

    $this->actingAs($this->usuario)
        ->get(route('portal.evaluacion-periodo-prueba'))
        ->assertInertia(fn (Assert $p) => $p
            ->where('evaluacion.estado', 'devuelta')
            ->where('evaluacion.puede_contestar', true)
            ->where('evaluacion.motivo_devolucion', 'Revisa tu calificación de puntualidad.')
            ->where('evaluacion.criterios.0.calificacion', 3));

    // El colaborador la corrige (app) y RH autoriza la renovación.
    Sanctum::actingAs($this->usuario);
    $this->postJson("/api/v1/colaborador/evaluacion-periodo-prueba/{$this->evaluacion->id}/contestar", autoCaptura(4))
        ->assertOk()
        ->assertJsonPath('data.estado', 'capturada');

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$this->evaluacion->id}/autorizar", ['renovar' => true])
        ->assertOk()
        ->assertJsonPath('data.decision_renovar', true);

    expect($this->evaluacion->refresh()->estado)->toBe(EstadoEvaluacionPrueba::Autorizada)
        ->and(ContratoLaboral::query()->where('contrato_anterior_id', $this->contrato->id)->exists())->toBeTrue();
});

test('el bootstrap móvil expone la evaluación propia y nadie captura por otra persona', function () {
    $datos = app(MobileBootstrapService::class)->bootstrap($this->usuario)['ciclo_laboral'];

    expect($datos['acciones']['capturar_evaluacion'])->toBeFalse()
        ->and($datos['acciones']['contestar_mi_evaluacion'])->toBeTrue()
        ->and($datos['propio']['evaluacion_periodo_prueba']['id'])->toBe($this->evaluacion->id)
        ->and($datos['propio']['evaluacion_periodo_prueba']['puede_contestar'])->toBeTrue()
        ->and(collect($datos['propio']['pendientes'])->pluck('clave'))->toContain('evaluacion_periodo_prueba');

    expect(app(MobileBootstrapService::class)->bootstrap($this->jefe)['ciclo_laboral']['acciones']['capturar_evaluacion'])->toBeFalse();
});

test('a quien no tiene cuenta no se le puede pedir contestar: RH recibe la tarea de darle acceso', function () {
    $sinCuenta = Colaborador::factory()->create(['jefe_id' => $this->jefe->colaborador_id, 'estatus' => 'activo', 'estado_alta' => null]);
    ContratoLaboral::factory()->create(['colaborador_id' => $sinCuenta->id, 'fecha_fin' => now()->addDays(8)->toDateString()]);

    $evaluacion = app(VencimientoContratosService::class)->asignarAhora($sinCuenta, $this->rh);
    $tarea = TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->where('relacionado_id', $evaluacion->id)->sole();

    expect($tarea->asignado_user_id)->toBeNull()
        ->and($tarea->asignado_permiso)->toBe(EvaluacionPeriodoPruebaService::PERMISO_AUTORIZAR)
        ->and($tarea->titulo)->toContain('Dar acceso')
        ->and(User::query()->where('colaborador_id', $sinCuenta->id)->exists())->toBeFalse();
});
