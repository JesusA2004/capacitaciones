<?php

use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoUsuario;
use App\Enums\NivelEvaluacionInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingModulo;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Evaluación oficial del Programa de Inducción (FO-RH-EI, 30 puntos) —
| docs/onboarding/Evaluacion_Induccion_Mr_Lana 2026.pdf. Casos exigidos por
| CLAUDE.md/el encargo: precarga, 30 puntos, 24 aprueba, 23 no aprueba,
| clave nunca llega al colaborador, casos prácticos RH con rúbrica,
| reintento en 5 días hábiles, histórico de intentos conservado.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');

    $this->modulo = OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $this->usuario = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->usuario->assignRole('colaborador');

    $this->contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]);

    $this->onboarding = app(OnboardingService::class);
    $this->evaluaciones = app(EvaluacionInduccionService::class);
    $this->proceso = $this->onboarding->iniciar($this->colaborador, $this->contrato, $this->rh);
});

afterEach(fn () => Carbon::setTestNow());

/** Marca como visto el avance institucional y habilita la evaluación formal. */
function eiHabilitar(object $t): EvaluacionInduccion
{
    $avance = $t->proceso->avances()->firstOrFail();
    $t->onboarding->marcarContenidoVisto($avance, $t->usuario);

    return $t->evaluaciones->habilitarPara($t->proceso->refresh());
}

/** Respuestas 100% correctas contra config/induccion_evaluacion.php. */
function eiRespuestasCorrectas(): array
{
    $a = [];
    foreach (config('induccion_evaluacion.seccion_a') as $i => $p) {
        $a[$i] = $p['correcta'];
    }
    $b = [];
    foreach (config('induccion_evaluacion.seccion_b') as $i => $p) {
        $b[$i] = $p['correcto'];
    }
    $c = [];
    foreach (config('induccion_evaluacion.seccion_c.conceptos') as $i => $concepto) {
        $c[$i] = $concepto['letra_correcta'];
    }
    $d = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        $d[$caso['clave']] = 'Respuesta del colaborador para '.$caso['clave'].'.';
    }

    return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d];
}

/** Todos los puntos de la rúbrica otorgados (5/5 en D). */
function eiPuntosDCompletos(): array
{
    $puntos = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        foreach ($caso['rubrica'] as $criterio) {
            $puntos[$caso['clave']][$criterio['clave']] = true;
        }
    }

    return $puntos;
}

test('la evaluación formal no se habilita hasta que el contenido obligatorio fue visto', function () {
    expect($this->evaluaciones->habilitarPara($this->proceso))->toBeNull();

    $avance = $this->proceso->avances()->firstOrFail();
    $this->onboarding->marcarContenidoVisto($avance, $this->usuario);
    $evaluacion = $this->evaluaciones->habilitarPara($this->proceso->refresh());

    expect($evaluacion)->not->toBeNull()
        ->and($evaluacion->estado)->toBe(EstadoEvaluacionInduccion::Disponible)
        ->and($evaluacion->version)->toBe(config('induccion_evaluacion.version'))
        // Idempotente: una segunda llamada no crea otra fila.
        ->and($this->evaluaciones->habilitarPara($this->proceso->refresh())->id)->toBe($evaluacion->id)
        ->and(EvaluacionInduccion::query()->where('onboarding_proceso_id', $this->proceso->id)->count())->toBe(1);
});

test('el quiz genérico de onboarding rechaza un módulo con evaluación formal', function () {
    $avance = $this->proceso->avances()->firstOrFail();

    expect(fn () => $this->onboarding->presentarEvaluacion($avance, $this->usuario, [0 => 0]))
        ->toThrow(ValidationException::class);
});

test('precarga: aArray trae los datos del colaborador sin pedírselos de nuevo', function () {
    $evaluacion = eiHabilitar($this);
    $datos = $this->evaluaciones->aArray($evaluacion, $this->usuario);

    expect($datos['colaborador']['nombre'])->toBe($this->colaborador->nombreCompleto())
        ->and($datos['colaborador']['puesto'])->toBe($this->estructura['puesto']->nombre)
        ->and($datos['colaborador']['sucursal'])->toBe($this->estructura['sucursal']->nombre)
        ->and($datos['colaborador']['fecha_ingreso'])->toBe($this->colaborador->fecha_ingreso->toDateString())
        ->and($datos['puede_presentar'])->toBeTrue();
});

test('la clave de respuestas y la rúbrica nunca salen en el contenido público', function () {
    $contenido = $this->evaluaciones->contenidoPublico();
    $json = json_encode($contenido);

    foreach (config('induccion_evaluacion.seccion_a') as $pregunta) {
        expect(str_contains($json, '"correcta"'))->toBeFalse();
    }

    expect($json)->not->toContain('"correcto"')
        ->not->toContain('"letra_correcta"')
        ->not->toContain('"rubrica"')
        ->not->toContain('Identifica la conducta como malversación de fondos');

    expect(array_key_exists('a', $contenido['seccion_a'][0] ?? []))->toBeFalse();
});

test('30/30: respuestas perfectas en A/B/C + rúbrica completa en D', function () {
    $evaluacion = eiHabilitar($this);
    $intento = $this->evaluaciones->presentar($evaluacion, $this->usuario, eiRespuestasCorrectas(), [
        'claridad' => 5, 'conocimiento_puesto' => 5, 'bienvenida' => 5, 'tema_reforzar' => null,
    ]);

    expect((float) $intento->calificacion_a)->toBe(12.0)
        ->and((float) $intento->calificacion_b)->toBe(8.0)
        ->and((float) $intento->calificacion_c)->toBe(5.0)
        ->and($intento->calificacion_d)->toBeNull()
        ->and($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::PendienteCalificacionRh);

    $this->actingAs($this->rh);
    $intento = $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, eiPuntosDCompletos());

    expect((float) $intento->calificacion_d)->toBe(5.0)
        ->and((float) $intento->calificacion_total)->toBe(30.0)
        ->and($intento->nivel)->toBe(NivelEvaluacionInduccion::Sobresaliente)
        ->and($intento->aprobado)->toBeTrue()
        ->and($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::Aprobada);

    // Aprobar la evaluación formal aprueba el avance institucional y avanza el onboarding.
    $avance = $this->proceso->avances()->firstOrFail();
    expect($avance->refresh()->estado)->toBe(EstadoAvanceOnboarding::Aprobado)
        ->and($this->proceso->refresh()->estado)->not->toBe(EstadoOnboarding::InduccionInstitucional);
});

test('24/30 aprueba; 23/30 requiere reforzamiento con plazo de 5 días hábiles', function () {
    // --- 23 puntos: A=12 + B=8 + C=3 (dos mal) + D=0 => 23, NO aprueba.
    $evaluacion = eiHabilitar($this);
    $respuestas = eiRespuestasCorrectas();
    $conceptos = config('induccion_evaluacion.seccion_c.conceptos');
    $respuestas['c'][0] = $conceptos[0]['letra_correcta'] === 'A' ? 'B' : 'A'; // una mal
    $respuestas['c'][1] = $conceptos[1]['letra_correcta'] === 'A' ? 'B' : 'A'; // otra mal
    $intento = $this->evaluaciones->presentar($evaluacion, $this->usuario, $respuestas, null);

    $this->actingAs($this->rh);
    $intento = $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, []); // D = 0

    expect((float) $intento->calificacion_total)->toBe(23.0)
        ->and($intento->aprobado)->toBeFalse()
        ->and($intento->nivel)->toBe(NivelEvaluacionInduccion::RequiereReforzamiento)
        ->and($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::RequiereReforzamiento)
        // Jueves 2026-10-01 (calificado_en) + 5 días hábiles = jueves 2026-10-08 (salta el fin de semana).
        ->and($evaluacion->fecha_limite_reevaluacion?->toDateString())->toBe('2026-10-08')
        ->and(TareaRh::query()->where('tipo', TipoTarea::InduccionReforzamiento->value)->whereNull('resuelta_en')->count())->toBe(1);

    // Sin retroalimentación de RH no hay reintento.
    expect(fn () => $this->evaluaciones->presentar($evaluacion->refresh(), $this->usuario, eiRespuestasCorrectas(), null))
        ->toThrow(ValidationException::class);

    $this->evaluaciones->retroalimentar($evaluacion, $this->rh, 'Repasa los principios y relaciona columnas.');
    expect($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::ReevaluacionHabilitada)
        ->and(TareaRh::query()->where('tipo', TipoTarea::InduccionReforzamiento->value)->whereNull('resuelta_en')->count())->toBe(0);

    // --- Segundo intento: 24/30 exacto (A=12 + B=8 + C=4 + D=0) sí aprueba.
    Carbon::setTestNow(now()->addDay());
    $respuestas2 = eiRespuestasCorrectas();
    $respuestas2['c'][0] = $conceptos[0]['letra_correcta'] === 'A' ? 'B' : 'A'; // una sola mal
    $intento2 = $this->evaluaciones->presentar($evaluacion->refresh(), $this->usuario, $respuestas2, null);
    $intento2 = $this->evaluaciones->calificarCasosPracticos($intento2, $this->rh, []);

    expect((float) $intento2->calificacion_total)->toBe(24.0)
        ->and($intento2->aprobado)->toBeTrue()
        ->and($intento2->nivel)->toBe(NivelEvaluacionInduccion::Aprobado)
        ->and($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::Aprobada);

    // Histórico: NUNCA se sobrescribe un intento anterior.
    expect($evaluacion->intentos()->count())->toBe(2)
        ->and($evaluacion->intentos()->where('numero', 1)->firstOrFail()->calificacion_total)->toEqualWithDelta(23.0, 0.001)
        ->and($evaluacion->intentos()->where('numero', 2)->firstOrFail()->calificacion_total)->toEqualWithDelta(24.0, 0.001);
});

test('los casos prácticos se califican con la rúbrica oficial y no se puede calificar dos veces', function () {
    $evaluacion = eiHabilitar($this);
    $intento = $this->evaluaciones->presentar($evaluacion, $this->usuario, eiRespuestasCorrectas(), null);

    $this->actingAs($this->rh);
    $parcial = ['caso_26' => ['identifica_malversacion' => true, 'cero_tolerancia' => true, 'reporta' => false], 'caso_27' => ['consulta_carpeta' => true, 'version_vigente' => false]];
    $intento = $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, $parcial);

    expect((float) $intento->calificacion_d)->toBe(3.0)
        ->and($intento->pendienteCalificacionRh())->toBeFalse();

    expect(fn () => $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, eiPuntosDCompletos()))
        ->toThrow(ValidationException::class);
});

test('un colaborador no puede presentar la evaluación de otro', function () {
    $evaluacion = eiHabilitar($this);
    $otro = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $otro->assignRole('colaborador');

    expect(fn () => $this->evaluaciones->presentar($evaluacion, $otro, eiRespuestasCorrectas(), null))
        ->toThrow(AuthorizationException::class);
});

test('el módulo con evaluación formal nunca aparece como lección del quiz genérico', function () {
    $ciclo = app(CicloLaboralService::class);

    expect($ciclo->misPendientes($this->colaborador, $this->usuario)['lecciones'])->toBe([]);

    eiHabilitar($this);

    expect($ciclo->misPendientes($this->colaborador, $this->usuario)['lecciones'])->toBe([]);
});

test('mi-proceso web: "todo_listo" no esconde contenido de inducción ni una evaluación por presentar', function () {
    // Contenido institucional sin ver todavía: "todo_listo" no debe decir
    // que ya no hay nada pendiente (ver induccionContenido.completado).
    $this->actingAs($this->usuario)->get('/mi-proceso')->assertInertia(fn ($page) => $page
        ->where('todo_listo', false)
        ->where('induccionContenido.completado', false)
        ->where('evaluacionInduccion', null));

    eiHabilitar($this);

    $this->actingAs($this->usuario)->get('/mi-proceso')->assertInertia(fn ($page) => $page
        ->where('todo_listo', false)
        ->where('induccionContenido.completado', true)
        ->where('evaluacionInduccion.puede_presentar', true)
        ->where('evaluacionInduccion.estado', 'disponible'));
});

test('el colaborador registra su progreso de capítulos y el contenido se marca visto al completar el 100%', function () {
    $this->modulo->update(['contenido' => json_encode([
        ['clave' => 'cap_1', 'numero' => 1, 'titulo' => 'Uno', 'bloques' => []],
        ['clave' => 'cap_2', 'numero' => 2, 'titulo' => 'Dos', 'bloques' => []],
    ])]);
    $avance = $this->proceso->avances()->firstOrFail();

    $avance = $this->onboarding->registrarProgresoCapitulo($avance, $this->usuario, 'cap_1');
    expect($avance->progreso_contenido['porcentaje'])->toBe(50)
        ->and($avance->contenido_visto_en)->toBeNull();

    $avance = $this->onboarding->registrarProgresoCapitulo($avance, $this->usuario, 'cap_2');
    expect($avance->progreso_contenido['porcentaje'])->toBe(100)
        ->and($avance->contenido_visto_en)->not->toBeNull();
});

test('registrar progreso de un capítulo que no existe en el contenido del módulo falla', function () {
    $this->modulo->update(['contenido' => json_encode([
        ['clave' => 'cap_1', 'numero' => 1, 'titulo' => 'Uno', 'bloques' => []],
    ])]);
    $avance = $this->proceso->avances()->firstOrFail();

    expect(fn () => $this->onboarding->registrarProgresoCapitulo($avance, $this->usuario, 'no_existe'))
        ->toThrow(ValidationException::class);
});

test('solo el colaborador titular registra su propio progreso de capítulos', function () {
    $this->modulo->update(['contenido' => json_encode([
        ['clave' => 'cap_1', 'numero' => 1, 'titulo' => 'Uno', 'bloques' => []],
    ])]);
    $avance = $this->proceso->avances()->firstOrFail();
    $otro = clUsuario('colaborador');

    expect(fn () => $this->onboarding->registrarProgresoCapitulo($avance, $otro, 'cap_1'))
        ->toThrow(AuthorizationException::class);
});

test('RH sin permiso no puede calificar los casos prácticos', function () {
    $evaluacion = eiHabilitar($this);
    $intento = $this->evaluaciones->presentar($evaluacion, $this->usuario, eiRespuestasCorrectas(), null);
    $sinPermiso = clUsuario('colaborador');

    expect(fn () => $this->evaluaciones->calificarCasosPracticos($intento, $sinPermiso, eiPuntosDCompletos()))
        ->toThrow(AuthorizationException::class);
});

test('secuencia: aprobar la inducción formal desbloquea SOLO el siguiente módulo, sin saltarse ninguno y sin desbloquear dos aunque se repita', function () {
    $puestoA = OnboardingModulo::query()->create(['clave' => 'puesto_a', 'titulo' => 'Puesto A', 'tipo' => TipoModuloOnboarding::Puesto, 'puesto_id' => $this->estructura['puesto']->id, 'orden' => 2, 'obligatorio' => true, 'activo' => true, 'preguntas' => [['pregunta' => '¿1+1?', 'opciones' => ['1', '2'], 'correcta' => 1]], 'calificacion_minima' => 8]);
    $puestoB = OnboardingModulo::query()->create(['clave' => 'puesto_b', 'titulo' => 'Puesto B', 'tipo' => TipoModuloOnboarding::Puesto, 'puesto_id' => $this->estructura['puesto']->id, 'orden' => 3, 'obligatorio' => true, 'activo' => true, 'preguntas' => [['pregunta' => '¿2+2?', 'opciones' => ['4', '5'], 'correcta' => 0]], 'calificacion_minima' => 8]);

    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    $proceso = $this->onboarding->iniciar($colaborador, ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id]), $this->rh);

    $estados = fn () => $proceso->avances()->orderBy('orden')->with('modulo')->get()->mapWithKeys(fn ($a) => [$a->modulo->clave => $a->estado])->all();

    expect($estados())->toBe([
        'induccion_mr_lana_2026' => EstadoAvanceOnboarding::Disponible,
        'puesto_a' => EstadoAvanceOnboarding::Bloqueado,
        'puesto_b' => EstadoAvanceOnboarding::Bloqueado,
    ]);

    $avanceInduccion = $proceso->avances()->whereHas('modulo', fn ($q) => $q->where('clave', 'induccion_mr_lana_2026'))->firstOrFail();
    $this->onboarding->marcarContenidoVisto($avanceInduccion, $usuario);
    $evaluacion = $this->evaluaciones->habilitarPara($proceso->refresh());
    $intento = $this->evaluaciones->presentar($evaluacion, $usuario, eiRespuestasCorrectas(), null);
    $this->actingAs($this->rh);
    $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, eiPuntosDCompletos());

    expect($estados())->toBe([
        'induccion_mr_lana_2026' => EstadoAvanceOnboarding::Aprobado,
        'puesto_a' => EstadoAvanceOnboarding::Disponible,
        'puesto_b' => EstadoAvanceOnboarding::Bloqueado,
    ]);

    // Una segunda aprobación formal (reintento/recalificación) no abre otro módulo.
    $this->onboarding->aprobarAvancesFormal($proceso->refresh(), 'induccion_mr_lana_2026');
    expect($estados()['puesto_b'])->toBe(EstadoAvanceOnboarding::Bloqueado);

    // Aprobar el módulo A abre el B: nunca queda bloqueado indefinidamente.
    $avanceA = $proceso->avances()->where('onboarding_modulo_id', $puestoA->id)->firstOrFail();
    $this->onboarding->presentarEvaluacion($avanceA, $usuario, [0 => 1]);
    expect($estados())->toBe([
        'induccion_mr_lana_2026' => EstadoAvanceOnboarding::Aprobado,
        'puesto_a' => EstadoAvanceOnboarding::Aprobado,
        'puesto_b' => EstadoAvanceOnboarding::Disponible,
    ]);

    $avanceB = $proceso->avances()->where('onboarding_modulo_id', $puestoB->id)->firstOrFail();
    $this->onboarding->presentarEvaluacion($avanceB, $usuario, [0 => 0]);
    expect(collect($estados())->every(fn ($e) => $e === EstadoAvanceOnboarding::Aprobado))->toBeTrue();
});

test('API móvil del colaborador: ningún JSON de inducción trae clave de respuestas, rúbrica ni criterio; RH solo la ve con permiso', function () {
    $token = $this->usuario->createToken('test')->plainTextToken;
    $cabeceras = ['Authorization' => "Bearer {$token}"];
    $evaluacion = eiHabilitar($this);
    $avance = $this->proceso->avances()->firstOrFail();

    $respuestas = [
        $this->withHeaders($cabeceras)->getJson('/api/v1/colaborador/induccion')->assertOk(),
        $this->withHeaders($cabeceras)->getJson('/api/v1/colaborador/mi-proceso')->assertOk(),
        $this->withHeaders($cabeceras)->getJson("/api/v1/colaborador/induccion/capitulos/{$avance->id}")->assertOk(),
        $this->withHeaders($cabeceras)->postJson("/api/v1/colaborador/induccion/{$evaluacion->id}/presentar", ['respuestas' => eiRespuestasCorrectas()])->assertOk(),
        $this->withHeaders($cabeceras)->getJson('/api/v1/colaborador/mi-proceso')->assertOk(),
    ];

    foreach ($respuestas as $respuesta) {
        $json = $respuesta->getContent();
        foreach (['"correcta"', '"correcto"', '"letra_correcta"', '"rubrica"', '"criterio"', 'identifica_malversacion', 'Identifica la conducta como malversación'] as $prohibido) {
            expect($json)->not->toContain($prohibido);
        }
        expect($respuesta->json('data.evaluacionInduccion.casos_practicos'))->toBeNull();
    }

    $intento = $evaluacion->intentos()->firstOrFail();

    // El propio colaborador (sin permiso de RH) no puede abrir la vista de calificación.
    $this->withHeaders($cabeceras)->getJson("/api/v1/rh/induccion/intentos/{$intento->id}")->assertForbidden();

    // RH con permiso sí ve la rúbrica oficial (forgetGuards: el guard de
    // Sanctum conserva al usuario anterior entre peticiones de una prueba).
    $this->app['auth']->forgetGuards();
    $tokenRh = $this->rh->createToken('test')->plainTextToken;
    $this->withHeaders(['Authorization' => "Bearer {$tokenRh}"])
        ->getJson("/api/v1/rh/induccion/intentos/{$intento->id}")
        ->assertOk()
        ->assertJsonPath('data.casos_practicos.0.rubrica.0.clave', 'identifica_malversacion');
});

test('modo presentación: RH y el colaborador ven los 7 capítulos reales sin registrar progreso ni ver la clave', function () {
    $this->modulo->update(['contenido' => json_encode([
        ['clave' => 'quienes_somos', 'numero' => 1, 'titulo' => '¿Quiénes somos?', 'bloques' => [['tipo' => 'parrafo', 'texto' => 'Texto real.']]],
        ['clave' => 'organigrama', 'numero' => 4, 'titulo' => 'Organigrama', 'bloques' => [['tipo' => 'organigrama_dinamico', 'vista' => 'corporativo', 'titulo' => 'Corporativo']]],
    ])]);
    $avance = $this->proceso->avances()->firstOrFail();

    foreach ([$this->rh, $this->usuario] as $quien) {
        $this->actingAs($quien)->get(route('induccion.presentacion'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/Induccion')
                ->where('modoPresentacion', true)
                ->where('avanceId', null)
                ->where('capitulos', fn ($capitulos) => count($capitulos) === 2));
    }

    expect($avance->refresh()->progreso_contenido)->toBeNull()
        ->and($avance->contenido_visto_en)->toBeNull();

    $this->actingAs($this->rh)->get(route('induccion.presentacion'))
        ->assertDontSee('"correcta"', false)
        ->assertDontSee('"rubrica"', false);
});
