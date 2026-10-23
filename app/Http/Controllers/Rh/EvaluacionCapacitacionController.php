<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoEvaluacionPrueba;
use App\Http\Controllers\Controller;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Contratos\EvaluacionPeriodoPruebaPdfService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\PeriodosPruebaService;
use App\Services\Contratos\VencimientoContratosService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Evaluaciones de periodo de prueba» (FO-RH-EPP-001): UN solo tablero con
 * tres pestañas —
 *  - Próximas / por asignar: quien sigue en periodo de prueba sin evaluación
 *    (PeriodosPruebaService) y «Asignar evaluación» antes de que el
 *    scheduler la abra sola (VencimientoContratosService::asignarAhora);
 *  - En proceso: esperando al colaborador (pendiente/devuelta: la contesta él) o por decidir RH
 *    (capturada);
 *  - Evaluadas: con respuestas, «Ver respuestas» y PDF para firma.
 * La contesta el colaborador en Mi espacio; la decisión de RH sigue en el ciclo de cada persona. Misma
 * fuente (EvaluacionPeriodoPruebaService) que la API.
 */
class EvaluacionCapacitacionController extends Controller
{
    private const PESTANA_PROXIMAS = 'proximas';

    public function __construct(
        private readonly EvaluacionPeriodoPruebaService $evaluaciones,
        private readonly PeriodosPruebaService $periodos,
        private readonly VencimientoContratosService $vencimientos,
        private readonly EvaluacionPeriodoPruebaPdfService $pdf,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        // Lectura global (p. ej. Sistemas con evaluaciones.ver_todos) consulta
        // sin capturar ni autorizar: eso lo deciden las Policies.
        abort_unless($this->periodos->puedeVer($usuario), 403);

        $puedeAsignar = $usuario->can('evaluaciones.autorizar') && $usuario->can('evaluaciones.asignar');
        [$pestana, $subfiltro] = $this->pestanaPedida($request, $puedeAsignar);
        $filtros = [
            'busqueda' => trim($request->string('busqueda')->toString()),
            'sucursal_id' => $request->integer('sucursal_id') ?: null,
            'solo_alerta' => $request->boolean('solo_alerta'),
            'subfiltro' => $subfiltro,
        ];

        $proximas = $this->periodos->porAsignar($usuario, $filtros);
        $conteos = $this->evaluaciones->conteosTablero($usuario, $filtros);
        $hoy = Carbon::today('America/Mexico_City');

        $evaluaciones = null;

        if ($pestana !== self::PESTANA_PROXIMAS) {
            $evaluaciones = $this->evaluaciones->listarTablero($usuario, $pestana, ['per_page' => $request->input('per_page')] + $filtros);
            (new EloquentCollection($evaluaciones->items()))->load(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);
            $documentos = $this->documentosRenovacion($evaluaciones->getCollection()->pluck('contrato_renovacion_id')->filter()->values());

            $evaluaciones->getCollection()->transform(fn (EvaluacionPeriodoPrueba $e) => $this->fila($e, $hoy, $documentos, $request));
        }

        return Inertia::render('Rh/Evaluaciones/Index', [
            'pestana' => $pestana,
            'evaluaciones' => $evaluaciones,
            'porAsignar' => $pestana === self::PESTANA_PROXIMAS ? $proximas['filas'] : null,
            'sinDuracion' => $proximas['sin_duracion'],
            'diasAlerta' => $proximas['dias_alerta'],
            'conteos' => [
                ...$conteos,
                'por_asignar' => $proximas['total'],
                'por_asignar_alerta' => $proximas['en_alerta'],
                'asignables' => $proximas['asignables'],
            ],
            'sucursales' => Sucursal::query()
                ->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario)->all())
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
                ->map(fn (Sucursal $s) => ['value' => $s->id, 'label' => $s->nombre])
                ->values(),
            'filtros' => [
                'busqueda' => $filtros['busqueda'],
                'sucursal_id' => $filtros['sucursal_id'],
                'solo_alerta' => $filtros['solo_alerta'],
                'subfiltro' => $subfiltro,
            ],
            'puedeAutorizar' => $usuario->can('evaluaciones.autorizar'),
            'puedeAsignar' => $puedeAsignar,
        ]);
    }

    /**
     * «Asignar evaluación» a uno o varios colaboradores: crea la evaluación
     * ahora para que la conteste el propio colaborador (autoevaluación), y le avisa.
     */
    public function asignar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('evaluaciones.autorizar') && $request->user()->can('evaluaciones.asignar'), 403);

        $datos = $request->validate([
            'colaboradores' => ['required', 'array', 'min:1', 'max:100'],
            'colaboradores.*' => ['integer', 'distinct'],
        ], [], ['colaboradores' => 'colaboradores']);

        $resultado = $this->vencimientos->asignarVarios(array_values(array_map('intval', $datos['colaboradores'])), $request->user());
        $asignados = count($resultado['asignados']);

        if ($asignados === 0) {
            return back()->with('toast', ['type' => 'error', 'message' => $resultado['omitidos'][0] ?? 'No se pudo asignar la evaluación.']);
        }

        $mensaje = $asignados === 1
            ? sprintf('Evaluación asignada a %s. Ya se le avisó para que la conteste en Mi espacio.', $resultado['asignados'][0])
            : sprintf('%d evaluaciones asignadas. Ya se le avisó a cada colaborador para que la conteste.', $asignados);

        if ($resultado['omitidos'] !== []) {
            return back()->with('toast', ['type' => 'warning', 'message' => sprintf('%s No se pudieron asignar %d: %s', $mensaje, count($resultado['omitidos']), implode(' ', $resultado['omitidos']))]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * PDF para firma de una evaluación ya contestada: inline (vista previa
     * dentro de PEOPLE) o, con ?descargar=1, como archivo.
     */
    public function pdf(Request $request, EvaluacionPeriodoPrueba $evaluacion): HttpResponse
    {
        Gate::authorize('descargar', $evaluacion);

        return $request->boolean('descargar')
            ? $this->pdf->descargar($evaluacion)
            : $this->pdf->vistaPrevia($evaluacion);
    }

    /**
     * Pestaña y sub-filtro pedidos. Compatibilidad: los enlaces viejos con
     * ?estado=… (p. ej. «Evaluación y decisión» = ?estado=capturada) caen
     * en la pestaña y el chip equivalentes.
     *
     * @return array{0: string, 1: string}
     */
    private function pestanaPedida(Request $request, bool $puedeAsignar): array
    {
        $pestanas = [self::PESTANA_PROXIMAS, EvaluacionPeriodoPruebaService::PESTANA_EN_PROCESO, EvaluacionPeriodoPruebaService::PESTANA_EVALUADAS];
        $pestana = $request->string('tab')->toString();
        $subfiltro = $request->string('subfiltro')->toString();

        // Enlaces viejos: «Esperando al jefe» ahora es «Esperando al colaborador».
        if ($subfiltro === EvaluacionPeriodoPruebaService::FILTRO_ESPERANDO_JEFE_LEGACY) {
            $subfiltro = EvaluacionPeriodoPruebaService::FILTRO_ESPERANDO_COLABORADOR;
        }

        $legado = EstadoEvaluacionPrueba::tryFrom($request->string('estado')->toString());

        if ($legado !== null && ! in_array($pestana, $pestanas, true)) {
            return match ($legado) {
                EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta => [EvaluacionPeriodoPruebaService::PESTANA_EN_PROCESO, EvaluacionPeriodoPruebaService::FILTRO_ESPERANDO_COLABORADOR],
                EstadoEvaluacionPrueba::Capturada => [EvaluacionPeriodoPruebaService::PESTANA_EN_PROCESO, EvaluacionPeriodoPruebaService::FILTRO_POR_DECIDIR],
                EstadoEvaluacionPrueba::Autorizada => [EvaluacionPeriodoPruebaService::PESTANA_EVALUADAS, EvaluacionPeriodoPruebaService::FILTRO_AUTORIZADAS],
            };
        }

        if (! in_array($pestana, $pestanas, true)) {
            // RH arranca por lo que hay que asignar; un jefe, por el seguimiento de su equipo.
            $pestana = $puedeAsignar ? self::PESTANA_PROXIMAS : EvaluacionPeriodoPruebaService::PESTANA_EN_PROCESO;
        }

        $validos = match ($pestana) {
            EvaluacionPeriodoPruebaService::PESTANA_EN_PROCESO => [EvaluacionPeriodoPruebaService::FILTRO_ESPERANDO_COLABORADOR, EvaluacionPeriodoPruebaService::FILTRO_POR_DECIDIR],
            EvaluacionPeriodoPruebaService::PESTANA_EVALUADAS => [EvaluacionPeriodoPruebaService::FILTRO_POR_DECIDIR, EvaluacionPeriodoPruebaService::FILTRO_AUTORIZADAS],
            default => [],
        };

        return [$pestana, in_array($subfiltro, $validos, true) ? $subfiltro : ''];
    }

    /**
     * @param  array<int, GeneratedDocument|null>  $documentos
     * @return array<string, mixed>
     */
    private function fila(EvaluacionPeriodoPrueba $e, Carbon $hoy, array $documentos, Request $request): array
    {
        $datos = $this->evaluaciones->aArray($e);
        $documento = $e->contrato_renovacion_id !== null ? ($documentos[$e->contrato_renovacion_id] ?? null) : null;
        $conRespuestas = $this->evaluaciones->tieneRespuestas($e);
        $usuario = $request->user();

        return [
            ...$datos,
            'puesto' => $e->colaborador->puesto?->nombre,
            'sucursal' => $e->colaborador->sucursalPrincipal?->nombre,
            'evaluador' => $e->evaluador?->nombreCompleto(),
            'dias_restantes' => $e->fecha_limite !== null ? (int) $hoy->diffInDays($e->fecha_limite, false) : null,
            'url' => route('rh.colaboradores.ciclo', $e->colaborador_id),
            'capturada_en' => $e->capturada_en?->toIso8601String(),
            'puede_decidir' => $e->estado === EstadoEvaluacionPrueba::Capturada && $usuario->can('autorizar', $e),
            'respuestas' => $conRespuestas ? $this->evaluaciones->respuestas($e) : null,
            'pdf_url' => $conRespuestas && $usuario->can('descargar', $e) ? route('rh.evaluaciones.pdf', $e->id) : null,
            'documento_renovacion' => $documento instanceof GeneratedDocument ? [
                'id' => $documento->id,
                'nombre' => $documento->original_name,
                'url' => route('rh.documentos-laborales.descargar', $documento->id),
            ] : null,
        ];
    }

    /**
     * «✓ Evaluación aprobada · Contrato indeterminado listo [Previsualizar]
     * [Imprimir]»: el documento vigente del contrato de renovación (el
     * contrato se crea una sola vez; aquí solo se localiza su PDF).
     *
     * @param  Collection<int, mixed>  $idsRenovacion
     * @return array<int, GeneratedDocument|null>
     */
    private function documentosRenovacion(Collection $idsRenovacion): array
    {
        $documentos = ContratoLaboral::query()
            ->with('documento:id,original_name')
            ->whereIn('id', $idsRenovacion)
            ->get(['id', 'generated_document_id'])
            ->mapWithKeys(fn (ContratoLaboral $c) => [$c->id => $c->documento]);
        // Respaldo: contratos cuyo PDF quedó ligado solo por documentable.
        $sinDocumento = $idsRenovacion->filter(fn ($id) => $documentos->get($id) === null)->values();

        if ($sinDocumento->isNotEmpty()) {
            GeneratedDocument::query()
                ->where('documentable_type', (new ContratoLaboral)->getMorphClass())
                ->whereIn('documentable_id', $sinDocumento)
                ->orderByDesc('id')
                ->get(['id', 'documentable_id', 'original_name'])
                ->unique('documentable_id')
                ->each(function (GeneratedDocument $d) use ($documentos): void {
                    $documentos->put($d->documentable_id, $d);
                });
        }

        return $documentos->all();
    }
}
