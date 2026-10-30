<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoAgendaEvento;
use App\Enums\MotivoNoViableAgenda;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reclutamiento\AgendarEventoRequest;
use App\Http\Requests\Reclutamiento\ResultadoSocioeconomicoAgendaRequest;
use App\Models\AgendaEvento;
use App\Services\Reclutamiento\AgendaConsultaService;
use App\Services\Reclutamiento\AgendaEventoService;
use App\Services\Reclutamiento\CvStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Mi agenda» en la app (contrato en docs/AGENDA_API_MOVIL.md). Llama a los
 * mismos Services que la pantalla web; el alcance y los permisos viven ahí.
 */
class AgendaController extends Controller
{
    public function __construct(
        private readonly AgendaEventoService $agenda,
        private readonly AgendaConsultaService $consulta,
        private readonly CvStorageService $cvStorage,
    ) {}

    /** Citas agrupadas por día; por defecto, de hoy en adelante y vigentes. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AgendaEvento::class);
        $usuario = $request->user();
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'sucursal_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'in:entrevista,socioeconomico'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);
        $filtros['desde'] ??= $this->agenda->ahora()->toDateString();

        $dias = $this->consulta->listar($usuario, $filtros)
            ->reject(fn (AgendaEvento $e): bool => ! isset($filtros['estado']) && in_array($e->estado, [EstadoAgendaEvento::Reprogramado, EstadoAgendaEvento::Cancelado], true))
            ->groupBy(fn (AgendaEvento $e): string => $e->fecha_hora->toDateString())
            ->map(fn ($eventos, string $fecha): array => [
                'fecha' => $fecha,
                'eventos' => $eventos->map(fn (AgendaEvento $e): array => $this->consulta->aArray($e, $usuario))->values()->all(),
            ])
            ->values();

        return response()->json([
            'data' => ['dias' => $dias],
            'meta' => [
                'motivos_no_viable' => array_map(fn (MotivoNoViableAgenda $m): array => ['value' => $m->value, 'etiqueta' => $m->etiqueta()], MotivoNoViableAgenda::cases()),
                'puede_agendar' => $usuario->can('agenda.agendar'),
                'puede_alta_candidato' => $usuario->can('agenda.alta_candidato'),
            ],
        ]);
    }

    /** Catálogos para agendar desde la app; candidatos vacíos sin agenda.agendar. */
    public function opciones(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AgendaEvento::class);
        $usuario = $request->user();

        return response()->json(['data' => [
            'sucursales' => $this->consulta->sucursalesVisibles($usuario)->values(),
            'candidatos' => $this->consulta->candidatosAgendables($usuario),
        ]]);
    }

    public function show(Request $request, AgendaEvento $evento): JsonResponse
    {
        $this->authorize('view', $evento);

        return $this->ficha($request, $evento);
    }

    public function store(AgendarEventoRequest $request): JsonResponse
    {
        $resultado = $this->agenda->agendar($request->safe()->except(['cv']), $request->user(), $request->file('cv'));

        return response()->json([
            'data' => $this->consulta->aArray($resultado['evento'], $request->user(), true),
            'empalmes' => $resultado['empalmes'],
            'repetido' => $resultado['repetido'],
        ], $resultado['repetido'] ? 200 : 201);
    }

    public function reprogramar(Request $request, AgendaEvento $evento): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
        $resultado = $this->agenda->reprogramar($evento, $datos['fecha'], $datos['hora'], $datos['motivo'] ?? null, $request->user());

        return response()->json(['data' => $this->consulta->aArray($resultado['evento'], $request->user(), true), 'empalmes' => $resultado['empalmes']]);
    }

    public function cancelar(Request $request, AgendaEvento $evento): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->ficha($request, $this->agenda->cancelar($evento, $datos['motivo'], $request->user()));
    }

    public function noAsistio(Request $request, AgendaEvento $evento): JsonResponse
    {
        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:2000']]);

        return $this->ficha($request, $this->agenda->registrarInasistencia($evento, $datos['comentario'] ?? null, $request->user()));
    }

    public function resultadoEntrevista(Request $request, AgendaEvento $evento): JsonResponse
    {
        $datos = $request->validate([
            'resultado' => ['required', 'in:viable,no_viable'],
            'motivo' => ['nullable', 'required_if:resultado,no_viable', 'string'],
            'comentario' => ['nullable', 'required_if:resultado,no_viable', 'string', 'max:2000'],
        ]);

        return $this->ficha($request, $this->agenda->registrarResultadoEntrevista($evento, $datos, $request->user()));
    }

    public function resultadoSocioeconomico(ResultadoSocioeconomicoAgendaRequest $request, AgendaEvento $evento): JsonResponse
    {
        return $this->ficha($request, $this->agenda->registrarResultadoSocioeconomico($evento, $request->safe()->except(['evidencias']), $request->archivos(), $request->user()));
    }

    public function cv(Request $request, AgendaEvento $evento): StreamedResponse
    {
        $this->authorize('verDatosCandidato', $evento);
        $candidato = $evento->candidato;
        abort_unless($candidato->cv_path !== null, 404);

        return $this->cvStorage->respuestaArchivo($request, $candidato->cv_path, (string) $candidato->cv_original_name);
    }

    private function ficha(Request $request, AgendaEvento $evento): JsonResponse
    {
        return response()->json(['data' => $this->consulta->aArray($evento->refresh(), $request->user(), true)]);
    }
}
