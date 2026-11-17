<?php

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoEvaluacionTecnica;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\EvaluacionTecnica;
use App\Models\PasoEvaluacionTecnica;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Services\EvaluacionesTecnicas\CalendarioEvaluacionTecnicaService;
use App\Services\EvaluacionesTecnicas\EvaluacionTecnicaService;
use App\Services\EvaluacionesTecnicas\PlantillasEvaluacionTecnicaService;
use App\Services\EvaluacionesTecnicas\ProgramacionEvaluacionTecnicaService;
use App\Services\EvaluacionesTecnicas\ResultadosEvaluacionTecnicaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

/*
| Evaluaciones técnicas («Seguimiento 15 / 30 / 60»): calendario del Gestor
| (2 meses de periodo de prueba), programación idempotente, inducción y
| periodo de prueba intactos, contestar/calificar y el contrato con Nine Box.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->rh = clUsuario('rh_admin');
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2]);

    // Formulario técnico publicado: una pregunta de 10 puntos con respuesta correcta.
    $this->plantilla = app(PlantillasEvaluacionTecnicaService::class)->crear($this->rh, ['nombre' => 'Técnica de cobranza']);
    $formularios = app(FormularioEvaluacionService::class);
    $borrador = $formularios->borrador(ClaveFormularioEvaluacion::Tecnica, $this->plantilla);
    $formularios->guardarBorrador($borrador, $this->rh, ['titulo' => 'Técnica de cobranza', 'definicion' => [
        'secciones' => [['titulo' => 'Cobranza', 'preguntas' => [[
            'clave' => 'p_dias', 'tipo' => 'opcion_multiple', 'enunciado' => '¿Cuándo se visita a un cliente con atraso?',
            'puntos' => 10, 'opciones' => [['clave' => 'o_hoy', 'texto' => 'El mismo día'], ['clave' => 'o_mes', 'texto' => 'Al mes']], 'correctas' => ['o_hoy'],
        ]]]],
    ]]);
    $formularios->publicar($borrador->refresh(), $this->rh);

    $calendario = app(CalendarioEvaluacionTecnicaService::class);
    $calendario->guardar($this->puesto, $this->rh, array_map(fn (int $dias): array => [
        'dias' => $dias, 'plantilla_id' => $this->plantilla->id, 'contesta' => 'colaborador',
        'calificacion_minima' => 80, 'dias_para_contestar' => 7, 'es_final' => $dias === 60,
    ], $calendario->sugerencia($this->puesto)));
});

test('el gestor de 2 meses tiene Seguimiento 15, 30 y 60; el programador genera solo la que ya toca, una vez, y no rompe los formularios fijos', function () {
    expect(PasoEvaluacionTecnica::query()->orderBy('dias')->pluck('nombre')->all())
        ->toBe(['Seguimiento 15', 'Seguimiento 30', 'Seguimiento 60']);

    $colaborador = Colaborador::factory()->create([
        'puesto_id' => $this->puesto->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => now()->subDays(16)->toDateString(),
    ]);
    User::factory()->create(['colaborador_id' => $colaborador->id])->assignRole('colaborador');

    $programador = app(ProgramacionEvaluacionTecnicaService::class);
    expect($programador->programar()['generadas'])->toBe(1)
        ->and($programador->programar()['generadas'])->toBe(0);

    $evaluacion = EvaluacionTecnica::query()->sole();
    expect($evaluacion->nombre)->toBe('Seguimiento 15')
        ->and($evaluacion->estado)->toBe(EstadoEvaluacionTecnica::Pendiente)
        ->and($evaluacion->fecha_limite->toDateString())->toBe(now()->subDays(1)->addDays(7)->toDateString());

    // Inducción y periodo de prueba siguen con su propia familia de versiones.
    $formularios = app(FormularioEvaluacionService::class);
    expect($formularios->asegurarVersionInicial(ClaveFormularioEvaluacion::Induccion)->familia)->toBe('induccion')
        ->and($formularios->publicado(ClaveFormularioEvaluacion::Induccion)?->version)->toBe(1)
        ->and($formularios->resumen())->toHaveCount(2);
});

test('el colaborador contesta su examen, se califica contra la mínima y Nine Box lee el promedio', function () {
    $colaborador = Colaborador::factory()->create([
        'puesto_id' => $this->puesto->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => now()->subDays(15)->toDateString(),
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    app(ProgramacionEvaluacionTecnicaService::class)->programar();
    $evaluacion = EvaluacionTecnica::query()->sole();

    // Otra persona no puede contestar el examen ajeno.
    expect(clUsuario('colaborador')->can('contestar', $evaluacion))->toBeFalse()
        ->and($usuario->can('contestar', $evaluacion))->toBeTrue();

    $this->actingAs($usuario)
        ->post(route('evaluaciones-tecnicas.responder', $evaluacion->id), ['respuestas' => ['p_dias' => 'o_hoy']])
        ->assertRedirect(route('evaluaciones-tecnicas.show', $evaluacion->id));

    $evaluacion->refresh();
    expect((float) $evaluacion->calificacion)->toBe(100.0)
        ->and($evaluacion->aprobada)->toBeTrue()
        ->and($usuario->can('contestar', $evaluacion))->toBeFalse();

    $promedios = app(ResultadosEvaluacionTecnicaService::class)->promedioPorColaborador([$colaborador->id, 999999], now()->subDay(), now()->addDay());
    expect($promedios)->toBe([$colaborador->id => 100.0])
        ->and(app(EvaluacionTecnicaService::class)->misEvaluaciones($usuario)['historial'])->toHaveCount(1);
});
