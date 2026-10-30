<?php

namespace App\Http\Controllers\Reclutamiento;

use App\Enums\EstadoAgendaEvento;
use App\Enums\MotivoNoViableAgenda;
use App\Enums\TipoAgendaEvento;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reclutamiento\AgendarEventoRequest;
use App\Http\Requests\Reclutamiento\ResultadoSocioeconomicoAgendaRequest;
use App\Models\AgendaEvento;
use App\Models\Puesto;
use App\Services\Reclutamiento\AgendaConsultaService;
use App\Services\Reclutamiento\AgendaEventoService;
use App\Services\Reclutamiento\CvStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reclutamiento → Agenda de entrevistas (pág. 23). Delgado: toda la regla vive en
 * AgendaEventoService y AgendaConsultaService, los mismos que usa la API móvil.
 */
class AgendaController extends Controller
{
    public function __construct(
        private readonly AgendaEventoService $agenda,
        private readonly AgendaConsultaService $consulta,
        private readonly CvStorageService $cvStorage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AgendaEvento::class);
        $usuario = $request->user();
        $filtros = $this->filtros($request);
        $periodo = ['desde' => $filtros['mes_desde'], 'hasta' => $filtros['mes_hasta'], 'sucursal_id' => $filtros['sucursal_id']];
        $eventoId = $request->integer('evento');
        $abierto = $eventoId > 0 ? $this->consulta->consulta($usuario)->whereKey($eventoId)->first() : null;

        return Inertia::render('Reclutamiento/Agenda/Index', [
            'filtros' => $filtros,
            'eventos' => $this->consulta->listar($usuario, $filtros)->map(fn (AgendaEvento $e): array => $this->consulta->aArray($e, $usuario))->values(),
            'indicadores' => $this->consulta->indicadores($usuario, $periodo),
            'record' => $this->consulta->recordPorSucursal($usuario, $periodo),
            'eventoAbierto' => $abierto !== null ? $this->consulta->aArray($abierto, $usuario, true) : null,
            'sucursales' => $this->consulta->sucursalesVisibles($usuario),
            'candidatos' => $this->consulta->candidatosAgendables($usuario),
            'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            'motivosNoViable' => array_map(fn (MotivoNoViableAgenda $m): array => ['value' => $m->value, 'etiqueta' => $m->etiqueta()], MotivoNoViableAgenda::cases()),
            'estados' => array_map(fn (EstadoAgendaEvento $e): array => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoAgendaEvento::cases()),
            'puede' => [
                'agendar' => $usuario->can('agenda.agendar'),
                'alta_candidato' => $usuario->can('agenda.alta_candidato'),
                'exportar' => $usuario->can('agenda.exportar'),
            ],
        ]);
    }

    public function store(AgendarEventoRequest $request): RedirectResponse
    {
        $resultado = $this->agenda->agendar($request->safe()->except(['cv']), $request->user(), $request->file('cv'));

        return back()->with('toast', [
            'type' => $resultado['empalmes'] !== [] ? 'warning' : 'success',
            'message' => $resultado['empalmes'] !== []
                ? sprintf('Evento agendado. Ojo: %s', implode(' ', $resultado['empalmes']))
                : 'Evento agendado. Se avisó a gerente y subgerente de la sucursal.',
        ]);
    }

    /** Aviso en vivo mientras se llena el formulario; no bloquea nada. */
    public function empalmes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AgendaEvento::class);
        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'excluir_id' => ['nullable', 'integer'],
        ]);
        abort_unless($this->consulta->sucursalesVisibles($request->user())->contains('id', (int) $datos['sucursal_id']), 403);

        return response()->json(['empalmes' => $this->agenda->empalmes(
            (int) $datos['sucursal_id'],
            Carbon::parse(sprintf('%s %s', $datos['fecha'], $datos['hora'])),
            null,
            isset($datos['excluir_id']) ? (int) $datos['excluir_id'] : null,
        )]);
    }

    public function reprogramar(Request $request, AgendaEvento $evento): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
        $resultado = $this->agenda->reprogramar($evento, $datos['fecha'], $datos['hora'], $datos['motivo'] ?? null, $request->user());

        return to_route('agenda.index', ['evento' => $resultado['evento']->id, 'fecha' => $resultado['evento']->fecha_hora->toDateString()])->with('toast', [
            'type' => $resultado['empalmes'] !== [] ? 'warning' : 'success',
            'message' => $resultado['empalmes'] !== [] ? sprintf('Reprogramada. Ojo: %s', implode(' ', $resultado['empalmes'])) : 'Cita reprogramada.',
        ]);
    }

    public function cancelar(Request $request, AgendaEvento $evento): RedirectResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']], ['motivo.required' => 'Indica el motivo de la cancelación.']);
        $this->agenda->cancelar($evento, $datos['motivo'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Cita cancelada.']);
    }

    public function noAsistio(Request $request, AgendaEvento $evento): RedirectResponse
    {
        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:2000']]);
        $this->agenda->registrarInasistencia($evento, $datos['comentario'] ?? null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Inasistencia registrada. Puedes reprogramar la cita.']);
    }

    public function resultadoEntrevista(Request $request, AgendaEvento $evento): RedirectResponse
    {
        $datos = $request->validate([
            'resultado' => ['required', 'in:viable,no_viable'],
            'motivo' => ['nullable', 'required_if:resultado,no_viable', 'string'],
            'comentario' => ['nullable', 'required_if:resultado,no_viable', 'string', 'max:2000'],
        ], ['motivo.required_if' => 'Elige el motivo.', 'comentario.required_if' => 'Escribe un comentario breve.']);
        $this->agenda->registrarResultadoEntrevista($evento, $datos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Resultado guardado. El candidato ya se actualizó en su proceso.']);
    }

    public function resultadoSocioeconomico(ResultadoSocioeconomicoAgendaRequest $request, AgendaEvento $evento): RedirectResponse
    {
        $this->agenda->registrarResultadoSocioeconomico($evento, $request->safe()->except(['evidencias']), $request->archivos(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Resultado y evidencia guardados en el expediente del candidato.']);
    }

    public function cv(Request $request, AgendaEvento $evento): StreamedResponse
    {
        $this->authorize('verDatosCandidato', $evento);
        $candidato = $evento->candidato;
        abort_unless($candidato->cv_path !== null, 404);

        return $this->cvStorage->respuestaArchivo($request, $candidato->cv_path, (string) $candidato->cv_original_name);
    }

    public function exportarExcel(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('agenda.exportar'), 403);
        $tabla = $this->tablaExportable($request);

        return Excel::download(new ReporteRhExport($tabla['titulo'], $tabla['columnas'], $tabla['filas']), sprintf('agenda-%s.xlsx', now()->format('Y-m-d')));
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        abort_unless($request->user()->can('agenda.exportar'), 403);

        return Pdf::loadView('pdf.reporte-rh', $this->tablaExportable($request))->setPaper('letter', 'landscape')
            ->download(sprintf('agenda-%s.pdf', now()->format('Y-m-d')));
    }

    /**
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, mixed>>}
     */
    private function tablaExportable(Request $request): array
    {
        $usuario = $request->user();
        $filtros = $this->filtros($request);

        if ($request->string('vista')->toString() === 'record') {
            return [
                'titulo' => sprintf('Récord por sucursal · %s a %s', $filtros['mes_desde'], $filtros['mes_hasta']),
                'columnas' => ['Sucursal', 'Agendadas', 'Realizadas', 'No asistió', '% inasistencia', 'Viables', '% viabilidad', 'Socioeconómicos', 'Días cita a resultado', '% por Reclutamiento', 'Agendadas mes anterior'],
                'filas' => array_map(fn (array $r): array => [
                    $r['sucursal'], $r['agendadas'], $r['realizadas'], $r['no_asistio'], $r['pct_inasistencia'] ?? 'Sin datos', $r['viables'], $r['pct_viabilidad'] ?? 'Sin datos',
                    $r['socioeconomicos_realizados'], $r['dias_cita_resultado'] ?? 'Sin datos', $r['pct_reclutamiento'] ?? 'Sin datos', $r['agendadas_mes_anterior'],
                ], $this->consulta->recordPorSucursal($usuario, ['desde' => $filtros['mes_desde'], 'hasta' => $filtros['mes_hasta'], 'sucursal_id' => $filtros['sucursal_id']])),
            ];
        }

        // Exportar no entrega teléfono ni domicilio: son solo la agenda de la operación.
        return [
            'titulo' => sprintf('Agenda de entrevistas · %s a %s', $filtros['desde'], $filtros['hasta']),
            'columnas' => ['Fecha', 'Hora', 'Tipo', 'Candidato', 'Puesto', 'Sucursal', 'Estatus', 'Resultado', 'Origen', 'Agendó'],
            'filas' => $this->consulta->listar($usuario, $filtros)->map(function (AgendaEvento $e) use ($usuario): array {
                $fila = $this->consulta->aArray($e, $usuario);

                return [$fila['fecha'], $fila['hora'], $fila['tipo_etiqueta'], $fila['candidato']['nombre'], $fila['candidato']['puesto'], $fila['sucursal']['nombre'], $fila['estado_etiqueta'], $fila['resultado_etiqueta'], $fila['origen_etiqueta'], $fila['agendado_por']];
            })->values()->all(),
        ];
    }

    /**
     * @return array{vista: string, ancla: string, desde: string, hasta: string, mes_desde: string, mes_hasta: string, sucursal_id: int|null, tipo: string|null, estado: string|null, busqueda: string|null}
     */
    private function filtros(Request $request): array
    {
        $datos = $request->validate([
            'vista' => ['nullable', 'in:semana,mes,lista,record'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'sucursal' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'in:'.implode(',', array_column(TipoAgendaEvento::cases(), 'value'))],
            'estado' => ['nullable', 'in:'.implode(',', array_column(EstadoAgendaEvento::cases(), 'value'))],
            'busqueda' => ['nullable', 'string', 'max:120'],
        ]);
        $vista = (string) ($datos['vista'] ?? 'semana');
        $ancla = Carbon::parse((string) ($datos['fecha'] ?? $this->agenda->ahora()->toDateString()));
        $inicioSemana = $ancla->copy()->startOfWeek(Carbon::MONDAY);
        [$desde, $hasta] = $vista === 'semana'
            ? [$inicioSemana, $inicioSemana->copy()->addDays(6)]
            : [$ancla->copy()->startOfMonth(), $ancla->copy()->endOfMonth()];

        return [
            'vista' => $vista,
            'ancla' => $ancla->toDateString(),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'mes_desde' => $ancla->copy()->startOfMonth()->toDateString(),
            'mes_hasta' => $ancla->copy()->endOfMonth()->toDateString(),
            'sucursal_id' => isset($datos['sucursal']) ? (int) $datos['sucursal'] : null,
            'tipo' => $datos['tipo'] ?? null,
            'estado' => $datos['estado'] ?? null,
            'busqueda' => $datos['busqueda'] ?? null,
        ];
    }
}
