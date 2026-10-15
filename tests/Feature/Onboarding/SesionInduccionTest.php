<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoSesionInduccion;
use App\Enums\EstadoUsuario;
use App\Enums\EtapaSeguimientoInduccion;
use App\Enums\ResultadoAsistenciaInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\SesionInduccion;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\SesionInduccionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Sesión de presentación de la Inducción Mr. Lana (camino principal): RH
| elige ANTES a quién se la presenta, al terminar «Envía la evaluación» →
| se habilita y se avisa en la app → el colaborador contesta → RH califica
| D → aprobada: laborando; reprobada: reforzamiento.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    // Al aprobar se adjunta el PDF FO-RH-EI al expediente (disco nas).
    Storage::fake('nas');

    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');

    OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $this->sesiones = app(SesionInduccionService::class);
    $this->evaluaciones = app(EvaluacionInduccionService::class);

    $this->ana = siNuevoIngreso($this, $this->estructura['sucursal']->id);
    $this->beto = siNuevoIngreso($this, $this->estructura['sucursal']->id);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Colaborador en su proceso de ingreso (onboarding abierto) con cuenta.
 *
 * @return array{colaborador: Colaborador, usuario: User, proceso: OnboardingProceso}
 */
function siNuevoIngreso(object $t, int $sucursalId): array
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursalId,
        'puesto_id' => $t->estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'estado_alta' => EstadoAltaColaborador::EnOnboarding,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id]);
    $proceso = app(OnboardingService::class)->iniciar($colaborador, $contrato, $t->rh);

    return ['colaborador' => $colaborador, 'usuario' => $usuario, 'proceso' => $proceso];
}

function siAvisos(User $usuario, string $tipo): int
{
    return Notification::sent($usuario, PendienteRhNotification::class)
        ->filter(fn (PendienteRhNotification $n): bool => $n->toDatabase($usuario)['tipo'] === $tipo)
        ->count();
}

function siAvisosEvaluacion(User $usuario): int
{
    return siAvisos($usuario, 'induccion_evaluacion_disponible');
}

function siRespuestas(bool $todoCorrecto = true): array
{
    $a = [];
    foreach (config('induccion_evaluacion.seccion_a') as $i => $p) {
        $a[$i] = $todoCorrecto ? $p['correcta'] : ($p['correcta'] + 1) % count($p['opciones']);
    }
    $b = [];
    foreach (config('induccion_evaluacion.seccion_b') as $i => $p) {
        $b[$i] = $todoCorrecto ? $p['correcto'] : ! $p['correcto'];
    }
    $c = [];
    foreach (config('induccion_evaluacion.seccion_c.conceptos') as $i => $concepto) {
        $c[$i] = $todoCorrecto ? $concepto['letra_correcta'] : 'Z';
    }
    $d = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        $d[$caso['clave']] = 'Respuesta para '.$caso['clave'].'.';
    }

    return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d];
}

function siPuntosD(bool $otorgar = true): array
{
    $puntos = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        foreach ($caso['rubrica'] as $criterio) {
            $puntos[$caso['clave']][$criterio['clave']] = $otorgar;
        }
    }

    return $puntos;
}

test('RH crea la sesión con 2 colaboradores de su alcance ANTES de presentar, sin habilitar ni avisar nada todavía', function () {
    $this->actingAs($this->rh)
        ->post('/rh/induccion/sesiones', ['colaborador_ids' => [$this->ana['colaborador']->id, $this->beto['colaborador']->id]])
        ->assertRedirect(route('induccion.presentacion'));

    $sesion = SesionInduccion::query()->sole();

    expect($sesion->estado)->toBe(EstadoSesionInduccion::EnCurso)
        ->and($sesion->presentada_por)->toBe($this->rh->id)
        ->and($sesion->asistentes()->pluck('colaborador_id')->sort()->values()->all())
        ->toBe(collect([$this->ana['colaborador']->id, $this->beto['colaborador']->id])->sort()->values()->all())
        // Nada se envía antes de «Enviar evaluación».
        ->and(EvaluacionInduccion::query()->count())->toBe(0)
        ->and(siAvisosEvaluacion($this->ana['usuario']))->toBe(0);

    // La pantalla de presentación la retoma al recargar.
    $this->actingAs($this->rh)->get('/induccion/presentacion')->assertOk()
        ->assertInertia(fn ($page) => $page->where('sesionRh.sesion_en_curso.id', $sesion->id)
            ->where('sesionRh.sesion_en_curso.asistentes.0.id', $this->ana['colaborador']->id));
});

test('fuera de alcance → 422; sin permiso → 403; lista vacía → 422', function () {
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id, 'nombre' => 'Cuautla']);
    $ajeno = siNuevoIngreso($this, $otraSucursal->id);

    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->givePermissionTo('onboarding.gestionar');

    // El buscador del gerente solo trae a los de su sucursal.
    $ids = collect($this->actingAs($gerente)->getJson('/rh/induccion/sesiones/colaboradores')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($this->ana['colaborador']->id)->not->toContain($ajeno['colaborador']->id);

    $this->actingAs($gerente)
        ->postJson('/rh/induccion/sesiones', ['colaborador_ids' => [$this->ana['colaborador']->id, $ajeno['colaborador']->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('colaborador_ids');

    $this->actingAs($this->rh)->postJson('/rh/induccion/sesiones', ['colaborador_ids' => []])->assertStatus(422);

    $this->actingAs($this->ana['usuario'])
        ->postJson('/rh/induccion/sesiones', ['colaborador_ids' => [$this->beto['colaborador']->id]])
        ->assertForbidden();

    expect(SesionInduccion::query()->count())->toBe(0);
});

test('«Enviar evaluación» habilita la evaluación de ambos, marca el contenido visto por RH y les avisa; repetirlo es idempotente', function () {
    $sesion = $this->sesiones->iniciar($this->rh, [$this->ana['colaborador']->id, $this->beto['colaborador']->id]);

    $this->actingAs($this->rh)->post("/rh/induccion/sesiones/{$sesion->id}/enviar")->assertRedirect();
    // Segunda vez: no duplica nada.
    $this->actingAs($this->rh)->post("/rh/induccion/sesiones/{$sesion->id}/enviar")->assertRedirect();

    expect($sesion->refresh()->estado)->toBe(EstadoSesionInduccion::Presentada)
        ->and(EvaluacionInduccion::query()->count())->toBe(2);

    foreach ([$this->ana, $this->beto] as $persona) {
        $evaluacion = EvaluacionInduccion::query()->where('colaborador_id', $persona['colaborador']->id)->sole();
        $avance = $persona['proceso']->avances()->firstOrFail();

        expect($evaluacion->estado)->toBe(EstadoEvaluacionInduccion::Disponible)
            ->and($avance->contenido_visto_en)->not->toBeNull()
            ->and($avance->progreso_contenido['presentada_por_rh']['sesion_id'])->toBe($sesion->id)
            ->and($avance->progreso_contenido['presentada_por_rh']['presentada_por'])->toBe($this->rh->id)
            ->and(siAvisosEvaluacion($persona['usuario']))->toBe(1)
            ->and(TareaRh::query()->where('tipo', TipoTarea::InduccionEvaluacionPendiente->value)->where('relacionado_id', $evaluacion->id)->count())->toBe(1);
    }

    Notification::assertSentTo($this->ana['usuario'], PendienteRhNotification::class, fn (PendienteRhNotification $n, array $c, User $d) => $n->toDatabase($d)['tipo'] === 'induccion_evaluacion_disponible'
        && $n->toDatabase($d)['titulo'] === EvaluacionInduccionService::TITULO_AVISO_PRESENTADA
        && $n->toDatabase($d)['accion'] === 'presentar_evaluacion_induccion');

    expect($sesion->asistentes()->pluck('resultado')->map(fn ($r) => $r->value)->unique()->values()->all())
        ->toBe([ResultadoAsistenciaInduccion::EvaluacionHabilitada->value]);

    // Seguimiento de RH: «Presentada · por contestar».
    $fila = collect($this->sesiones->seguimiento($this->rh))->firstWhere('colaborador.id', $this->ana['colaborador']->id);
    expect($fila['etapa'])->toBe(EtapaSeguimientoInduccion::PorContestar->value)
        ->and($fila['presentada_por_rh_en'])->not->toBeNull();
});

test('el colaborador contesta por API, RH recibe el aviso, califica D → aprobada → queda laborando', function () {
    $sesion = $this->sesiones->iniciar($this->rh, [$this->ana['colaborador']->id]);
    $this->sesiones->enviarEvaluacion($sesion, $this->rh);
    $evaluacion = EvaluacionInduccion::query()->where('colaborador_id', $this->ana['colaborador']->id)->sole();

    // mi-proceso de la app trae la evaluación lista para contestar.
    $this->actingAs($this->ana['usuario'], 'sanctum')
        ->getJson('/api/v1/colaborador/mi-proceso')->assertOk()
        ->assertJsonPath('data.evaluacionInduccion.puede_presentar', true)
        ->assertJsonPath('data.induccionContenido.completado', true);

    $this->actingAs($this->ana['usuario'], 'sanctum')
        ->postJson("/api/v1/colaborador/induccion/{$evaluacion->id}/presentar", ['respuestas' => siRespuestas()])
        ->assertOk();

    expect($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::PendienteCalificacionRh);
    // A RH le llega el aviso de que hay una evaluación por revisar.
    expect(siAvisos($this->rh, 'induccion_evaluacion_presentada'))->toBe(1);
    expect(collect($this->sesiones->seguimiento($this->rh))->firstWhere('colaborador.id', $this->ana['colaborador']->id)['etapa'])
        ->toBe(EtapaSeguimientoInduccion::PorCalificar->value);

    $intento = $evaluacion->intentos()->sole();

    // Revisión «en forma de sistema»: preguntas, respuesta y acierto.
    $this->actingAs($this->rh)->get("/rh/onboarding/induccion/intentos/{$intento->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('revision.seccion_a.0.acierto', true)
            ->where('revision.seccion_a.0.elegida', config('induccion_evaluacion.seccion_a.0.correcta')));

    $this->actingAs($this->rh)
        ->post("/rh/onboarding/induccion/intentos/{$intento->id}/calificar", ['puntos' => siPuntosD(), 'comentario' => 'Muy buen análisis.'])
        ->assertRedirect();

    $proceso = $this->ana['proceso']->refresh();

    expect($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::Aprobada)
        ->and($intento->refresh()->comentario_rh)->toBe('Muy buen análisis.')
        // Sin módulos de puesto ni activos pendientes: el onboarding se cierra
        // al aprobar (el colaborador queda listo para laborar).
        ->and($proceso->estado)->toBe(EstadoOnboarding::Completado)
        ->and(collect($this->sesiones->seguimiento($this->rh))->firstWhere('colaborador.id', $this->ana['colaborador']->id)['etapa'])
        ->toBe(EtapaSeguimientoInduccion::Aprobada->value);
});

test('si la aprueba y aún quedan módulos de puesto, el onboarding NO se cierra solo', function () {
    OnboardingModulo::query()->create([
        'clave' => 'modulo_puesto', 'titulo' => 'Gestor de ruta', 'tipo' => TipoModuloOnboarding::Puesto,
        'orden' => 1, 'obligatorio' => true, 'activo' => true, 'calificacion_minima' => 8,
        'preguntas' => [['pregunta' => '¿?', 'opciones' => ['a', 'b'], 'correcta' => 0]],
    ]);
    $carlos = siNuevoIngreso($this, $this->estructura['sucursal']->id);
    $sesion = $this->sesiones->iniciar($this->rh, [$carlos['colaborador']->id]);
    $this->sesiones->enviarEvaluacion($sesion, $this->rh);
    $evaluacion = EvaluacionInduccion::query()->where('colaborador_id', $carlos['colaborador']->id)->sole();
    $intento = $this->evaluaciones->presentar($evaluacion, $carlos['usuario'], siRespuestas(), null);
    $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, siPuntosD());

    expect($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::Aprobada)
        ->and($carlos['proceso']->refresh()->estado)->toBe(EstadoOnboarding::InduccionPuesto);
});

test('reprobada → reforzamiento con fecha límite; seguimiento lo muestra', function () {
    $sesion = $this->sesiones->iniciar($this->rh, [$this->beto['colaborador']->id]);
    $this->sesiones->enviarEvaluacion($sesion, $this->rh);
    $evaluacion = EvaluacionInduccion::query()->where('colaborador_id', $this->beto['colaborador']->id)->sole();
    $intento = $this->evaluaciones->presentar($evaluacion, $this->beto['usuario'], siRespuestas(false), null);
    $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, siPuntosD(false));

    expect($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::RequiereReforzamiento)
        ->and($evaluacion->fecha_limite_reevaluacion)->not->toBeNull()
        ->and($this->beto['proceso']->refresh()->estado)->toBe(EstadoOnboarding::InduccionInstitucional)
        ->and(collect($this->sesiones->seguimiento($this->rh))->firstWhere('colaborador.id', $this->beto['colaborador']->id)['etapa'])
        ->toBe(EtapaSeguimientoInduccion::Reforzamiento->value);
});

test('un colaborador no puede contestar la evaluación de otro (API)', function () {
    $sesion = $this->sesiones->iniciar($this->rh, [$this->ana['colaborador']->id, $this->beto['colaborador']->id]);
    $this->sesiones->enviarEvaluacion($sesion, $this->rh);
    $deAna = EvaluacionInduccion::query()->where('colaborador_id', $this->ana['colaborador']->id)->sole();

    $this->actingAs($this->beto['usuario'], 'sanctum')
        ->postJson("/api/v1/colaborador/induccion/{$deAna->id}/presentar", ['respuestas' => siRespuestas()])
        ->assertForbidden();

    expect($deAna->refresh()->intentos_count)->toBe(0);
});

test('presentada a alguien sin onboarding abierto: queda registrada y se aplica cuando su onboarding inicia', function () {
    $nuevo = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'estado_alta' => EstadoAltaColaborador::PendienteFirma,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $nuevo->id]);
    $usuario->assignRole('colaborador');

    $sesion = $this->sesiones->iniciar($this->rh, [$nuevo->id]);
    $this->sesiones->enviarEvaluacion($sesion, $this->rh);

    expect($sesion->asistentes()->sole()->resultado)->toBe(ResultadoAsistenciaInduccion::SinProcesoIngreso)
        ->and(EvaluacionInduccion::query()->where('colaborador_id', $nuevo->id)->exists())->toBeFalse();

    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $nuevo->id]);
    app(OnboardingService::class)->iniciar($nuevo, $contrato, $this->rh);

    expect(EvaluacionInduccion::query()->where('colaborador_id', $nuevo->id)->sole()->estado)->toBe(EstadoEvaluacionInduccion::Disponible)
        ->and(siAvisosEvaluacion($usuario))->toBe(1);
});

test('API de RH: mismas reglas que la web', function () {
    $this->actingAs($this->rh, 'sanctum')->getJson('/api/v1/rh/induccion/sesiones')->assertOk()
        ->assertJsonPath('data.sesion_en_curso', null);

    $id = $this->actingAs($this->rh, 'sanctum')
        ->postJson('/api/v1/rh/induccion/sesiones', ['colaborador_ids' => [$this->ana['colaborador']->id]])
        ->assertCreated()->json('data.id');

    $this->actingAs($this->rh, 'sanctum')->postJson("/api/v1/rh/induccion/sesiones/{$id}/enviar")->assertOk()
        ->assertJsonPath('data.estado', EstadoSesionInduccion::Presentada->value)
        ->assertJsonPath('data.asistentes.0.resultado', ResultadoAsistenciaInduccion::EvaluacionHabilitada->value);

    $this->actingAs($this->rh, 'sanctum')->postJson("/api/v1/rh/induccion/sesiones/{$id}/cancelar")->assertStatus(422);
});
