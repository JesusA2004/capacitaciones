<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoCandidato;
use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\DecisionAprobacionRequest;
use App\Http\Requests\Reclutamiento\DescartarCandidatoRequest;
use App\Http\Requests\Reclutamiento\EnviarPsicometricasRequest;
use App\Http\Requests\Reclutamiento\EvaluarFiltroCandidatoRequest;
use App\Http\Requests\Reclutamiento\RegistrarEntrevistaRequest;
use App\Http\Requests\Reclutamiento\RegistrarReferenciaRequest;
use App\Http\Requests\Reclutamiento\RegistrarSocioeconomicoRequest;
use App\Http\Requests\Reclutamiento\ResultadosPsicometricasRequest;
use App\Models\Candidato;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Reclutamiento\CandidatoPresenter;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reclutamiento para la app (gerente / RH). Mismos FormRequests y el MISMO
 * CandidatoWorkflowService que Rh\CandidatoController (web): cada acción
 * autoriza ahí (permiso + alcance + regla preautoriza/autoriza RH). La
 * respuesta siempre es la ficha + el estado del ciclo (con las acciones
 * permitidas para quien pregunta), así la app nunca decide por rol.
 */
class CandidatoController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly CandidatoWorkflowService $workflow,
        private readonly CandidatoPresenter $presenter,
        private readonly CicloLaboralService $ciclo,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Candidato::class);

        $filtros = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'string', 'max:40'],
            'sucursal_id' => ['nullable', 'integer'],
            'vacante_id' => ['nullable', 'integer'],
            'puesto_objetivo_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pagina = $this->presenter->consulta($request->user(), $filtros)
            ->orderByDesc('created_at')
            ->paginate((int) ($filtros['per_page'] ?? 20));

        return $this->paginado($pagina, fn (Candidato $c) => $this->presenter->fila($c), [
            'estados' => array_map(fn (EstadoCandidato $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoCandidato::cases()),
        ]);
    }

    public function show(Request $request, Candidato $candidato): JsonResponse
    {
        $this->authorize('view', $candidato);

        return $this->ficha($request, $candidato);
    }

    public function evaluarPerfil(EvaluarFiltroCandidatoRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->evaluarPerfil($candidato, $request->user(), $request->boolean('viable'), $request->validated('observaciones'));

        return $this->ficha($request, $candidato);
    }

    public function registrarEntrevista(RegistrarEntrevistaRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->registrarEntrevista($candidato, $request->user(), $request->validated());

        return $this->ficha($request, $candidato);
    }

    public function enviarPsicometricas(EnviarPsicometricasRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->enviarPsicometricas($candidato, $request->user(), (string) $request->validated('link'));

        return $this->ficha($request, $candidato);
    }

    public function resultadosPsicometricas(ResultadosPsicometricasRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->registrarResultadosPsicometricas($candidato, $request->user(), (string) $request->validated('resumen'), array_values($request->file('archivos', [])));

        return $this->ficha($request, $candidato);
    }

    public function revisarPsicometricas(EvaluarFiltroCandidatoRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->revisarPsicometricas($candidato, $request->user(), $request->boolean('viable'), $request->validated('observaciones'));

        return $this->ficha($request, $candidato);
    }

    public function registrarSocioeconomico(RegistrarSocioeconomicoRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->registrarSocioeconomico($candidato, $request->user(), $request->safe()->except('evidencias'), array_values($request->file('evidencias', [])));

        return $this->ficha($request, $candidato);
    }

    public function registrarReferencia(RegistrarReferenciaRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->registrarReferencia($candidato, $request->user(), $request->validated());

        return $this->ficha($request, $candidato);
    }

    public function concluirReferencias(EvaluarFiltroCandidatoRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->concluirReferencias($candidato, $request->user(), $request->boolean('viable'), $request->validated('observaciones'));

        return $this->ficha($request, $candidato);
    }

    public function preautorizar(DecisionAprobacionRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->preautorizar($candidato, $request->user(), $request->comentario());

        return $this->ficha($request, $candidato);
    }

    public function autorizarRh(DecisionAprobacionRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->autorizarRh($candidato, $request->user(), $request->comentario());

        return $this->ficha($request, $candidato);
    }

    public function rechazarRh(DecisionAprobacionRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->rechazarRh($candidato, $request->user(), $request->motivo());

        return $this->ficha($request, $candidato);
    }

    public function devolverRh(DecisionAprobacionRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->devolverRh($candidato, $request->user(), $request->motivo());

        return $this->ficha($request, $candidato);
    }

    public function descartar(DescartarCandidatoRequest $request, Candidato $candidato): JsonResponse
    {
        $this->workflow->descartar($candidato, $request->user(), EstadoCandidato::from((string) $request->validated('estado')), (string) $request->validated('motivo'));

        return $this->ficha($request, $candidato);
    }

    private function ficha(Request $request, Candidato $candidato): JsonResponse
    {
        $candidato->refresh();

        return response()->json(['data' => [
            'candidato' => $this->presenter->detalle($candidato),
            'ciclo' => $this->ciclo->obtenerEstado($candidato, $request->user()),
        ]]);
    }
}
