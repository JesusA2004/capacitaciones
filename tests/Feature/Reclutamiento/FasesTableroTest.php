<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoSesionInduccion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\OnboardingProceso;
use App\Models\SesionInduccion;
use App\Models\SesionInduccionColaborador;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Reclutamiento\FaseContratadoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Columnas del tablero de candidatos. Después de Contratación, un contratado
| avanza por hechos REALES de su colaborador (FaseContratadoService):
| Inducción → Evaluación de inducción → Capacitación inicial → Evaluación de
| capacitación inicial → Contrato indeterminado (solo con el contrato
| indeterminado YA GENERADO; nunca por activado_en).
*/

function ftContratado(array $colaborador = []): Candidato
{
    $persona = Colaborador::factory()->create($colaborador);

    return Candidato::factory()->create(['estado' => EstadoCandidato::Contratado, 'colaborador_id' => $persona->id]);
}

function ftSesion(Candidato $candidato, EstadoSesionInduccion $estado): void
{
    $sesion = SesionInduccion::query()->create([
        'presentada_por' => User::factory()->create()->id,
        'estado' => $estado,
        'iniciada_en' => now(),
        'presentada_en' => $estado === EstadoSesionInduccion::Presentada ? now() : null,
    ]);
    SesionInduccionColaborador::query()->create(['sesion_induccion_id' => $sesion->id, 'colaborador_id' => $candidato->colaborador_id]);
}

function ftEvaluacionInduccion(Candidato $candidato, EstadoEvaluacionInduccion $estado): void
{
    $proceso = OnboardingProceso::query()->create(['colaborador_id' => $candidato->colaborador_id, 'estado' => EstadoOnboarding::InduccionInstitucional, 'iniciado_en' => now()]);
    EvaluacionInduccion::query()->create([
        'onboarding_proceso_id' => $proceso->id, 'colaborador_id' => $candidato->colaborador_id,
        'clave' => 'induccion_mr_lana_2026', 'version' => '2026', 'estado' => $estado,
        'aprobada_en' => $estado === EstadoEvaluacionInduccion::Aprobada ? now() : null,
    ]);
}

function ftEvaluacionCapacitacion(Candidato $candidato): void
{
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $candidato->colaborador_id]);
    EvaluacionPeriodoPrueba::factory()->create(['contrato_laboral_id' => $contrato->id, 'colaborador_id' => $candidato->colaborador_id]);
}

function ftContratoIndeterminado(Candidato $candidato, ?EstadoFlujoDocumento $flujo = EstadoFlujoDocumento::Generado): void
{
    GeneratedDocument::factory()->create([
        'colaborador_id' => $candidato->colaborador_id,
        'clave_plantilla' => 'contrato_indeterminado',
        'estado_flujo' => $flujo,
    ]);
}

test('las columnas del tablero van en orden: … contratación → inducción → evaluación de inducción → capacitación inicial → evaluación de capacitación inicial → contrato indeterminado', function () {
    expect(array_keys(EstadoCandidato::fases()))->toBe([
        'filtro_rh', 'entrevista', 'psicometricos', 'socioeconomico', 'referencias', 'contratacion',
        'induccion', 'evaluacion_induccion', 'capacitacion_inicial', 'evaluacion_capacitacion_inicial', 'contrato_indeterminado',
        'rechazado', 'desistido',
    ])->and(EstadoCandidato::ReferenciasPendientes->faseCanonica())->toBe('referencias')
        ->and(EstadoCandidato::Contratado->etiqueta())->toBe('Contratado')
        ->and(EstadoCandidato::FASES_CONTRATADO)->toBe(['induccion', 'evaluacion_induccion', 'capacitacion_inicial', 'evaluacion_capacitacion_inicial', 'contrato_indeterminado']);
});

test('contratado sin nada registrado está en Inducción aunque su colaborador ya esté activado (no salta a contrato indeterminado)', function () {
    $candidato = ftContratado(['activado_en' => now()]);

    expect($candidato->fresh()->fase)->toBe('induccion')
        ->and($candidato->fresh()->etiquetaEstado())->toBe('Inducción');
});

test('una sesión de inducción presentada lo pasa a Evaluación de inducción; una sesión en curso no cuenta', function () {
    $enCurso = ftContratado();
    ftSesion($enCurso, EstadoSesionInduccion::EnCurso);
    expect($enCurso->fresh()->fase)->toBe('induccion');

    $presentada = ftContratado();
    ftSesion($presentada, EstadoSesionInduccion::Presentada);
    expect($presentada->fresh()->fase)->toBe('evaluacion_induccion')
        ->and($presentada->fresh()->etiquetaEstado())->toBe('Evaluación de inducción');
});

test('la evaluación de inducción aprobada lo pasa a Capacitación inicial; en reforzamiento sigue en Evaluación de inducción', function () {
    $refuerzo = ftContratado();
    ftEvaluacionInduccion($refuerzo, EstadoEvaluacionInduccion::RequiereReforzamiento);
    expect($refuerzo->fresh()->fase)->toBe('evaluacion_induccion');

    $aprobada = ftContratado();
    ftEvaluacionInduccion($aprobada, EstadoEvaluacionInduccion::Aprobada);
    expect($aprobada->fresh()->fase)->toBe('capacitacion_inicial')
        ->and($aprobada->fresh()->etiquetaEstado())->toBe('Capacitación inicial');
});

test('con evaluación de capacitación inicial asignada está en Evaluación de capacitación inicial', function () {
    $candidato = ftContratado();
    ftEvaluacionInduccion($candidato, EstadoEvaluacionInduccion::Aprobada);
    ftEvaluacionCapacitacion($candidato);

    expect($candidato->fresh()->fase)->toBe('evaluacion_capacitacion_inicial')
        ->and($candidato->fresh()->etiquetaEstado())->toBe('Evaluación de capacitación inicial');
});

test('solo el contrato indeterminado generado (no cancelado) lo lleva a Contrato indeterminado', function () {
    $cancelado = ftContratado(['activado_en' => now()]);
    ftEvaluacionCapacitacion($cancelado);
    ftContratoIndeterminado($cancelado, EstadoFlujoDocumento::Cancelado);
    expect($cancelado->fresh()->fase)->toBe('evaluacion_capacitacion_inicial');

    $generado = ftContratado();
    ftEvaluacionCapacitacion($generado);
    ftContratoIndeterminado($generado);
    expect($generado->fresh()->fase)->toBe('contrato_indeterminado')
        ->and($generado->fresh()->etiquetaEstado())->toBe('Contrato indeterminado');
});

test('gana el hito más avanzado: contrato indeterminado generado sin registros de inducción (expediente histórico)', function () {
    $candidato = ftContratado();
    ftContratoIndeterminado($candidato, null);

    expect($candidato->fresh()->fase)->toBe('contrato_indeterminado');
});

test('precargar calcula la fase en lote: consultas fijas sin importar cuántos contratados, y luego ninguna por tarjeta', function () {
    $contar = function (int $total): array {
        $candidatos = collect(range(1, $total))->map(function () {
            $c = ftContratado();
            ftEvaluacionInduccion($c, EstadoEvaluacionInduccion::Aprobada);

            return $c->fresh();
        });

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FaseContratadoService::class)->precargar($candidatos);
        $alPrecargar = count(DB::getQueryLog());

        DB::flushQueryLog();
        $fases = $candidatos->map(fn (Candidato $c): string => $c->fase)->unique()->values()->all();
        $alLeer = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$alPrecargar, $alLeer, $fases];
    };

    [$uno, $leerUno, $fasesUno] = $contar(1);
    [$cinco, $leerCinco, $fasesCinco] = $contar(5);

    expect($cinco)->toBe($uno)
        ->and($leerUno)->toBe(0)
        ->and($leerCinco)->toBe(0)
        ->and($fasesUno)->toBe(['capacitacion_inicial'])
        ->and($fasesCinco)->toBe(['capacitacion_inicial']);
});

test('el tablero manda a cada contratado a su columna real', function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $estructura = clEstructura();
    $rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $enSucursal = fn (): array => ['sucursal_principal_id' => $estructura['sucursal']->id];
    $crear = function (array $colaborador) use ($estructura): Candidato {
        $candidato = ftContratado($colaborador);
        $candidato->update(['empresa_id' => $estructura['empresa']->id, 'sucursal_id' => $estructura['sucursal']->id, 'puesto_objetivo_id' => $estructura['puesto']->id]);

        return $candidato;
    };

    $induccion = $crear($enSucursal() + ['activado_en' => now()]);
    $capacitacion = $crear($enSucursal());
    ftEvaluacionInduccion($capacitacion, EstadoEvaluacionInduccion::Aprobada);
    $indeterminado = $crear($enSucursal());
    ftContratoIndeterminado($indeterminado);

    $this->actingAs($rh)
        ->get(route('rh.candidatos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('candidatos', function ($lista) use ($induccion, $capacitacion, $indeterminado): bool {
            $fases = collect($lista)->pluck('fase', 'id');

            return $fases[$induccion->id] === 'induccion'
                && $fases[$capacitacion->id] === 'capacitacion_inicial'
                && $fases[$indeterminado->id] === 'contrato_indeterminado';
        }));
});

test('el paso a paso de la ficha usa las mismas columnas: todo lo previo completado, su fase actual y lo siguiente pendiente', function () {
    $candidato = ftContratado();
    ftEvaluacionInduccion($candidato, EstadoEvaluacionInduccion::Aprobada);

    $pasos = collect(app(CicloLaboralService::class)->obtenerEstado($candidato->fresh())['pasos'])->pluck('estado', 'clave');

    expect($pasos->only(['perfil', 'entrevista', 'referencias', 'autorizacion', 'contratacion', 'induccion', 'evaluacion_induccion'])->unique()->values()->all())->toBe(['completado'])
        ->and($pasos['capacitacion_inicial'])->toBe('actual')
        ->and($pasos['evaluacion_capacitacion_inicial'])->toBe('pendiente')
        ->and($pasos['contrato_indeterminado'])->toBe('pendiente');

    // La meta alcanzada se marca completada, no «actual».
    ftContratoIndeterminado($candidato);
    $final = collect(app(CicloLaboralService::class)->obtenerEstado($candidato->fresh())['pasos'])->pluck('estado', 'clave');
    expect($final->unique()->values()->all())->toBe(['completado']);
});
