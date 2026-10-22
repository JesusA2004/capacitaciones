<?php

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFormularioEvaluacion;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FormularioEvaluacion;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\User;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\FormulariosEvaluacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Módulo «Formularios de evaluación»: versión 1 = config/, versiones
| publicadas inmutables, cada evaluación conserva la versión con la que se
| contestó, contenido público sin respuestas y con la forma de siempre (app
| instalada), captura validada contra la versión, permisos.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Storage::fake('nas');
    Storage::fake('public');

    $this->formularios = app(FormularioEvaluacionService::class);
    $this->rh = clUsuario('rh_admin');
});

/** Onboarding con el módulo institucional ya visto: devuelve la evaluación habilitada. */
function feEvaluacionInduccion(object $t): EvaluacionInduccion
{
    $estructura = clEstructura();
    OnboardingModulo::query()->firstOrCreate(['clave' => 'induccion_mr_lana_2026'], [
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id]);

    $onboarding = app(OnboardingService::class);
    /** @var OnboardingProceso $proceso */
    $proceso = $onboarding->iniciar($colaborador, $contrato, $t->rh);
    $onboarding->marcarContenidoVisto($proceso->avances()->firstOrFail(), $usuario);
    $t->usuarioInduccion = $usuario;

    return app(EvaluacionInduccionService::class)->habilitarPara($proceso->refresh());
}

/** Respuestas 100% correctas contra una definición de inducción. */
function feRespuestasCorrectas(array $definicion): array
{
    return [
        'a' => array_map(fn (array $p) => $p['correcta'], $definicion['seccion_a']),
        'b' => array_map(fn (array $p) => $p['correcto'], $definicion['seccion_b']),
        'c' => array_map(fn (array $c) => $c['letra_correcta'], $definicion['seccion_c']['conceptos']),
        'd' => collect($definicion['seccion_d'])->mapWithKeys(fn (array $c) => [$c['clave'] => 'Mi respuesta.'])->all(),
    ];
}

/** Publica una versión nueva con la definición modificada por $cambiar. */
function fePublicarCon(object $t, ClaveFormularioEvaluacion $clave, callable $cambiar): FormularioEvaluacion
{
    $borrador = $t->formularios->crearBorrador($clave, $t->rh);
    $definicion = $cambiar($t->formularios->normalizar($clave, $borrador->definicion));
    $t->formularios->guardarBorrador($borrador, $t->rh, ['titulo' => $borrador->titulo, 'definicion' => $definicion]);

    return $t->formularios->publicar($borrador->refresh(), $t->rh);
}

test('el seeder crea la versión 1 publicada idéntica a config y es idempotente', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);
    $this->seed(FormulariosEvaluacionSeeder::class);

    expect(FormularioEvaluacion::query()->count())->toBe(2);

    $induccion = $this->formularios->publicado(ClaveFormularioEvaluacion::Induccion);
    expect($induccion->version)->toBe(1)
        ->and($induccion->etiquetaVersion())->toBe(config('induccion_evaluacion.version'));

    $desdeFila = $this->formularios->contenidoPublicoInduccion($this->formularios->definicionDe(ClaveFormularioEvaluacion::Induccion, $induccion));
    $desdeConfig = $this->formularios->contenidoPublicoInduccion($this->formularios->definicionDe(ClaveFormularioEvaluacion::Induccion, null));
    unset($desdeFila['formulario'], $desdeConfig['formulario']);

    expect($desdeFila)->toBe($desdeConfig)
        ->and($desdeFila['instrucciones'])->toBe(['calificacion_aprobatoria' => 24, 'total_puntos' => 30])
        ->and($desdeFila['seccion_e'])->toEqual(config('induccion_evaluacion.seccion_e'));

    $prueba = $this->formularios->definicionPeriodoPrueba();
    expect(array_column($prueba['criterios'], 'clave'))->toBe(array_column(config('contratos.evaluacion_periodo_prueba_v2.criterios'), 'clave'))
        ->and(array_column($prueba['filtros'], 'etiqueta'))->toBe(array_column(config('contratos.evaluacion_periodo_prueba_v2.filtros'), 'etiqueta'))
        ->and($prueba['total_maximo'])->toBe(36)
        ->and($prueba['total_minimo_renovar'])->toBe(25);
});

test('el contenido público conserva la forma de siempre y nunca trae respuestas ni rúbrica', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);
    $contenido = app(EvaluacionInduccionService::class)->contenidoPublico();

    expect(array_keys($contenido))->toBe(['version', 'instrucciones', 'seccion_a', 'seccion_b', 'seccion_c', 'seccion_d', 'seccion_e', 'formulario'])
        ->and(array_keys($contenido['seccion_a'][0]))->toBe(['indice', 'pregunta', 'opciones'])
        ->and(array_keys($contenido['seccion_b'][0]))->toBe(['indice', 'afirmacion'])
        ->and(array_keys($contenido['seccion_c']))->toBe(['conceptos', 'descripciones'])
        ->and(array_keys($contenido['seccion_c']['conceptos'][0]))->toBe(['indice', 'concepto'])
        ->and(array_keys($contenido['seccion_d'][0]))->toBe(['clave', 'puntos_max', 'enunciado'])
        ->and($contenido['seccion_e'])->toHaveKeys(['aspectos', 'escala_min', 'escala_max']);

    $json = (string) json_encode($contenido);
    expect($json)->not->toContain('"correcta"')
        ->not->toContain('"correcto"')
        ->not->toContain('letra_correcta')
        ->not->toContain('rubrica');
});

test('publicar archiva la versión anterior y una versión publicada ya no se edita', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);

    $v2 = fePublicarCon($this, ClaveFormularioEvaluacion::Induccion, function (array $d): array {
        $d['seccion_a'][0]['pregunta'] = 'Pregunta nueva de la versión 2';

        return $d;
    });

    expect($v2->version)->toBe(2)
        ->and($v2->estado)->toBe(EstadoFormularioEvaluacion::Publicado)
        ->and(FormularioEvaluacion::query()->where('clave', 'induccion')->where('version', 1)->value('estado'))->toBe(EstadoFormularioEvaluacion::Archivado)
        ->and(app(EvaluacionInduccionService::class)->contenidoPublico()['seccion_a'][0]['pregunta'])->toBe('Pregunta nueva de la versión 2');

    expect(fn () => $this->formularios->guardarBorrador($v2, $this->rh, ['titulo' => 'X', 'definicion' => $v2->definicion]))
        ->toThrow(ValidationException::class);
});

test('no se publica un borrador incompleto', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);
    $borrador = $this->formularios->crearBorrador(ClaveFormularioEvaluacion::Induccion, $this->rh);
    $definicion = $borrador->definicion;
    $definicion['seccion_a'][0]['pregunta'] = '';
    $definicion['seccion_d'] = [];
    $this->formularios->guardarBorrador($borrador, $this->rh, ['titulo' => 'Evaluación', 'definicion' => $definicion]);

    expect($this->formularios->problemas($borrador->refresh()))->toContain('Sección A, pregunta 1: escribe la pregunta.')
        ->toContain('La sección D necesita al menos un caso práctico.')
        ->and(fn () => $this->formularios->publicar($borrador, $this->rh))->toThrow(ValidationException::class);
});

test('una evaluación contestada con la versión 1 se sigue calificando con la versión 1 aunque se publique la 2', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);
    $v1 = $this->formularios->publicado(ClaveFormularioEvaluacion::Induccion);
    $evaluacion = feEvaluacionInduccion($this);

    expect($evaluacion->formulario_evaluacion_id)->toBe($v1->id);

    // La versión 2 cambia la respuesta correcta de la pregunta 1 y vale 5 puntos.
    $v2 = fePublicarCon($this, ClaveFormularioEvaluacion::Induccion, function (array $d): array {
        $d['seccion_a'][0]['correcta'] = ($d['seccion_a'][0]['correcta'] + 1) % count($d['seccion_a'][0]['opciones']);
        $d['seccion_a'][0]['puntos'] = 5;

        return $d;
    });

    $servicio = app(EvaluacionInduccionService::class);
    $definicionV1 = $this->formularios->definicionDe(ClaveFormularioEvaluacion::Induccion, $v1);
    $intento = $servicio->presentar($evaluacion, $this->usuarioInduccion, feRespuestasCorrectas($definicionV1), null);

    expect((float) $intento->calificacion_a)->toBe(12.0)
        ->and($evaluacion->refresh()->formulario_evaluacion_id)->toBe($v1->id)
        ->and($servicio->contenidoPublico($evaluacion)['instrucciones']['total_puntos'])->toBe(30);

    $puntos = collect($definicionV1['seccion_d'])->mapWithKeys(fn (array $c) => [$c['clave'] => collect($c['rubrica'])->mapWithKeys(fn (array $r) => [$r['clave'] => true])->all()])->all();
    $calificado = $servicio->calificarCasosPracticos($intento, $this->rh, $puntos);

    expect((float) $calificado->calificacion_total)->toBe(30.0);

    // Una evaluación NUEVA ya nace con la versión 2.
    $nueva = feEvaluacionInduccion($this);
    expect($nueva->formulario_evaluacion_id)->toBe($v2->id)
        ->and($nueva->version)->toBe($v2->etiquetaVersion())
        ->and($servicio->contenidoPublico($nueva)['instrucciones']['total_puntos'])->toBe(34);
});

test('la captura del periodo de prueba se valida y califica con la versión del formulario', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);
    clPlantilla('contrato_indeterminado');
    $jefe = clUsuario('jefe_directo');
    $colaborador = Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id]);
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'fecha_fin' => now()->addDays(5)->toDateString()]);

    // Versión 2: tres criterios, sin preguntas filtro, renueva con 9 de 12.
    $v2 = fePublicarCon($this, ClaveFormularioEvaluacion::PeriodoPrueba, fn (array $d): array => [
        ...$d,
        'criterios' => [
            ['clave' => 'actitud', 'etiqueta' => 'Actitud'],
            ['clave' => 'puntualidad', 'etiqueta' => 'Puntualidad'],
            ['clave' => 'resultados', 'etiqueta' => 'Resultados'],
        ],
        'filtros' => [],
        'total_minimo_renovar' => 9,
    ]);

    $evaluacion = EvaluacionPeriodoPrueba::factory()->create([
        'colaborador_id' => $colaborador->id,
        'contrato_laboral_id' => $contrato->id,
        'evaluador_colaborador_id' => $jefe->colaborador_id,
        'estado' => EstadoEvaluacionPrueba::Pendiente,
        'fecha_limite' => $contrato->fecha_fin,
    ]);

    // Los 9 criterios de config ya no son los de esta evaluación.
    $this->actingAs($jefe)
        ->post(route('rh.evaluaciones.capturar', $evaluacion), [
            'criterios' => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => 4])->all(),
            'filtros' => [],
        ])
        ->assertSessionHasErrors('criterios');

    $capturada = app(EvaluacionPeriodoPruebaService::class)->capturar($evaluacion, $jefe, [
        'criterios' => [
            ['clave' => 'actitud', 'calificacion' => 3],
            ['clave' => 'puntualidad', 'calificacion' => 3],
            ['clave' => 'resultados', 'calificacion' => 3],
        ],
        'filtros' => [],
    ]);

    expect((float) $capturada->calificacion)->toBe(9.0)
        ->and($capturada->recomienda_renovar)->toBeTrue()
        ->and($capturada->formulario_evaluacion_id)->toBe($v2->id)
        ->and(app(EvaluacionPeriodoPruebaService::class)->aArray($capturada->refresh())['total_maximo'])->toBe(12);
});

test('solo quien administra formularios de evaluación entra al módulo', function () {
    $this->seed(FormulariosEvaluacionSeeder::class);
    $formulario = $this->formularios->publicado(ClaveFormularioEvaluacion::Induccion);

    $this->actingAs(clUsuario('rh_auxiliar'))->get(route('rh.formularios-evaluacion.index'))->assertForbidden();
    $this->actingAs(clUsuario('colaborador'))->get(route('rh.formularios-evaluacion.editar', 'induccion'))->assertForbidden();
    $this->actingAs(clUsuario('jefe_directo'))->put(route('rh.formularios-evaluacion.guardar', $formulario), ['titulo' => 'X', 'definicion' => ['a' => 1]])->assertForbidden();
    $this->actingAs(clUsuario('rh_auxiliar'))->post(route('rh.formularios-evaluacion.borrador', 'induccion'))->assertForbidden();

    $this->actingAs($this->rh)->get(route('rh.formularios-evaluacion.index'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.formularios-evaluacion.editar', 'periodo_prueba'))->assertOk();
    $this->actingAs($this->rh)->post(route('rh.formularios-evaluacion.borrador', 'induccion'))->assertRedirect(route('rh.formularios-evaluacion.editar', 'induccion'));

    expect($this->formularios->borrador(ClaveFormularioEvaluacion::Induccion)?->version)->toBe(2);
});
