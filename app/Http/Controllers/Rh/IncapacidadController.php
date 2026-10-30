<?php

namespace App\Http\Controllers\Rh;

use App\Enums\SubtipoRiesgoTrabajo;
use App\Enums\TipoDocumentoIncapacidad;
use App\Enums\TipoIncapacidad;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incapacidades\RegistrarIncapacidadRequest;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\IncapacidadDocumento;
use App\Models\NominaRectificacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Incapacidades\IncapacidadConsultaService;
use App\Services\Incapacidades\IncapacidadService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Nomina\NominaQuincenalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permanencia → Incapacidades (Etapa 1, págs. 7-9). Delgado: las reglas viven
 * en IncapacidadService e IncidenciasNominaService.
 */
class IncapacidadController extends Controller
{
    public function __construct(
        private readonly IncapacidadService $incapacidades,
        private readonly IncapacidadConsultaService $consulta,
        private readonly IncidenciasNominaService $incidencias,
        private readonly NominaQuincenalService $quincenas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('incapacidades.ver'), 403);
        $filtros = $this->filtros($request);
        $usuario = $request->user();
        $periodo = $this->periodoImpacto($request);
        $mes = isset($filtros['mes']) ? (string) $filtros['mes'] : null;
        $sucursalId = isset($filtros['sucursal_id']) ? (string) $filtros['sucursal_id'] : null;

        return Inertia::render('Rh/Incapacidades/Index', [
            'incapacidades' => $this->consulta->listar($usuario, $filtros)->through(fn (Incapacidad $i): array => $this->consulta->aArray($i, $usuario)),
            'indicadores' => $this->consulta->indicadores($usuario, $mes, $sucursalId),
            'filtros' => [...$filtros, 'mes' => $this->consulta->rangoMes($mes)[0]->format('Y-m'), 'periodo' => $periodo['clave']],
            'tipos' => TipoIncapacidad::opciones(),
            'sucursales' => $this->sucursales($usuario),
            'quincenas' => $this->quincenas->periodosParaSelector(),
            'impacto' => [
                'clave' => $periodo['clave'],
                'etiqueta' => $periodo['etiqueta'],
                'filas' => $this->consulta->impactoNomina($usuario, $periodo['inicio'], $periodo['fin'], $sucursalId),
                'regla' => $this->incidencias->reglaParaPantalla(),
            ],
            'rectificaciones' => $usuario->can('nomina.recibos.crear') ? $this->consulta->rectificacionesPendientes($usuario) : [],
            'puede' => [
                'registrar' => $usuario->can('incapacidades.registrar'),
                'exportar' => $usuario->can('incapacidades.exportar'),
                'rectificar' => $usuario->can('nomina.recibos.crear'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('incapacidades.registrar'), 403);

        return Inertia::render('Rh/Incapacidades/Registrar', [
            'tipos' => TipoIncapacidad::opciones(),
            'subtipos' => SubtipoRiesgoTrabajo::opciones(),
            'regla' => $this->incidencias->reglaParaPantalla(),
            'mimes' => (array) config('incapacidades.documentos.mimes', []),
            'maxKb' => (int) config('incapacidades.documentos.max_kb', 15360),
        ]);
    }

    /** Buscador de colaboradores del formulario, dentro del alcance del módulo. */
    public function colaboradores(Request $request, BuscadorColaboradoresService $buscador): JsonResponse
    {
        abort_unless($request->user()->can('incapacidades.registrar'), 403);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $query = Colaborador::query()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
        $this->alcance->limitarPorModulo($query, $request->user(), 'incapacidades', 'id');

        if ($request->user()->colaborador_id !== null) {
            $query->where('id', '!=', $request->user()->colaborador_id);
        }

        $filas = $buscador->aplicar($query, (string) ($datos['q'] ?? ''))->orderBy('name')->limit(30)->get();

        return response()->json(['data' => $filas->map(fn (Colaborador $c): array => [
            'id' => $c->id,
            'nombre' => $c->nombreCompleto(),
            'numero_empleado' => $c->numero_empleado,
            'puesto' => $c->puesto?->nombre,
            'sucursal' => $c->sucursalPrincipal?->nombre,
        ])->values()->all()]);
    }

    /** Iniciales abiertas de la persona, para ligar una subsecuente. */
    public function iniciales(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($request->user()->can('incapacidades.registrar') && $this->alcance->alcanzaColaboradorEnModulo($request->user(), $colaborador, 'incapacidades'), 403);

        return response()->json(['data' => $this->consulta->inicialesAbiertas($colaborador)]);
    }

    public function store(RegistrarIncapacidadRequest $request): RedirectResponse
    {
        $colaborador = Colaborador::query()->where('id', $request->integer('colaborador_id'))->firstOrFail();
        abort_unless($this->alcance->alcanzaColaboradorEnModulo($request->user(), $colaborador, 'incapacidades') && $request->user()->colaborador_id !== $colaborador->id, 403);

        $incapacidad = $this->incapacidades->registrar($colaborador, $request->validated(), $request->file('certificado'), $request->file('st7'), $request->user());

        return redirect()->route('rh.incapacidades.show', $incapacidad->raizId())
            ->with('success', sprintf('Incapacidad registrada. Termina el %s.', $incapacidad->fecha_fin->format('d/m/Y')));
    }

    /** Expediente de una incapacidad con su cadena. */
    public function show(Request $request, Incapacidad $incapacidad): Response
    {
        $this->authorize('ver', $incapacidad);
        $raiz = $this->incapacidades->raizDe($incapacidad);

        return Inertia::render('Rh/Incapacidades/Expediente', [
            ...$this->datosExpediente($raiz->colaborador, [$this->consulta->cadena($raiz, $request->user())], $request->user()),
            'pdfRaizId' => $raiz->id,
        ]);
    }

    /** Historial completo de una persona: todas sus cadenas. */
    public function historial(Request $request, Colaborador $colaborador): Response
    {
        abort_unless($request->user()->can('incapacidades.ver') && $request->user()->colaborador_id !== $colaborador->id && $this->alcance->alcanzaColaboradorEnModulo($request->user(), $colaborador, 'incapacidades'), 403);
        $usuario = $request->user();
        $raices = Incapacidad::query()->where('colaborador_id', $colaborador->id)->whereNull('incapacidad_inicial_id')->orderByDesc('fecha_inicio')->get();

        return Inertia::render('Rh/Incapacidades/Expediente', [
            ...$this->datosExpediente($colaborador, $raices->map(fn (Incapacidad $r): array => $this->consulta->cadena($r, $usuario))->all(), $usuario),
            'pdfRaizId' => null,
        ]);
    }

    public function corregir(Request $request, Incapacidad $incapacidad): RedirectResponse
    {
        $this->authorize('corregir', $incapacidad);
        $datos = $request->validate([
            'serie_folio_imss' => ['required', 'string', 'max:40'],
            'fecha_inicio' => ['required', 'date'],
            'dias' => ['required', 'integer', 'min:1', 'max:'.(int) config('incapacidades.dias_maximos', 365)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], ['motivo.required' => 'Indica el motivo de la corrección.']);

        $this->incapacidades->corregir($incapacidad, $datos, $datos['motivo'], $request->user());

        return back()->with('success', 'Corrección guardada y registrada en la bitácora.');
    }

    public function cancelar(Request $request, Incapacidad $incapacidad): RedirectResponse
    {
        $this->authorize('corregir', $incapacidad);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], ['motivo.required' => 'Indica el motivo de la cancelación.']);
        $this->incapacidades->cancelar($incapacidad, $datos['motivo'], $request->user());

        return back()->with('success', 'Incapacidad cancelada. El registro y sus documentos se conservan.');
    }

    public function subirDocumento(Request $request, Incapacidad $incapacidad): RedirectResponse
    {
        $this->authorize('subirDocumento', $incapacidad);
        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoDocumentoIncapacidad::class)],
            'archivo' => ['required', 'file', 'mimes:'.implode(',', (array) config('incapacidades.documentos.mimes', [])), 'max:'.(int) config('incapacidades.documentos.max_kb', 15360)],
            'fecha_documento' => ['nullable', 'date'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ], ['archivo.required' => 'Elige el archivo (PDF o imagen).']);

        $this->incapacidades->subirDocumento($incapacidad, TipoDocumentoIncapacidad::from($datos['tipo']), $request->file('archivo'), $datos['fecha_documento'] ?? null, $datos['motivo'] ?? null, $request->user());

        return back()->with('success', 'Documento cargado.');
    }

    public function descargarDocumento(Request $request, IncapacidadDocumento $documento): StreamedResponse
    {
        $this->authorize('descargar', $documento->incapacidad);

        return $this->incapacidades->descargarDocumento($documento, $request->user());
    }

    /** Expediente del riesgo o de la incapacidad en PDF. */
    public function pdf(Request $request, Incapacidad $incapacidad): SymfonyResponse
    {
        $this->authorize('descargar', $incapacidad);
        $raiz = $this->incapacidades->raizDe($incapacidad);
        $titulo = $raiz->riesgo_folio !== null ? sprintf('Expediente del riesgo de trabajo %s', $raiz->riesgo_folio) : sprintf('Expediente de incapacidad %s', $raiz->serie_folio_imss);

        return Pdf::loadView('pdf.incapacidades.expediente', [
            'titulo' => $titulo,
            'colaborador' => $raiz->colaborador->loadMissing(['puesto', 'sucursalPrincipal']),
            'cadena' => $this->consulta->cadena($raiz, $request->user()),
        ])->download(sprintf('%s.pdf', $raiz->riesgo_folio ?? 'incapacidad-'.$raiz->id));
    }

    public function exportarExcel(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('incapacidades.exportar'), 403);
        $tabla = $this->consulta->tablaExportable($request->user(), $this->filtros($request));

        return Excel::download(new ReporteRhExport($tabla['titulo'], $tabla['columnas'], $tabla['filas']), sprintf('incapacidades-%s.xlsx', now()->format('Y-m-d')));
    }

    public function exportarPdf(Request $request): SymfonyResponse
    {
        abort_unless($request->user()->can('incapacidades.exportar'), 403);
        $tabla = $this->consulta->tablaExportable($request->user(), $this->filtros($request));

        return Pdf::loadView('pdf.reporte-rh', $tabla)->setPaper('letter', 'landscape')->download(sprintf('incapacidades-%s.pdf', now()->format('Y-m-d')));
    }

    public function aplicarRectificacion(Request $request, NominaRectificacion $rectificacion): RedirectResponse
    {
        $this->autorizarRectificacion($request->user(), $rectificacion);
        $this->incidencias->aplicarRectificacion($rectificacion, $request->user());

        return back()->with('success', 'Rectificación aplicada: se generó un PDF nuevo y el anterior queda en el historial.');
    }

    public function descartarRectificacion(Request $request, NominaRectificacion $rectificacion): RedirectResponse
    {
        $this->autorizarRectificacion($request->user(), $rectificacion);
        $datos = $request->validate(['nota' => ['required', 'string', 'min:5', 'max:500']], ['nota.required' => 'Indica por qué se descarta.']);
        $this->incidencias->descartarRectificacion($rectificacion, $datos['nota'], $request->user());

        return back()->with('success', 'Rectificación descartada; el recibo emitido no cambió.');
    }

    private function autorizarRectificacion(User $usuario, NominaRectificacion $rectificacion): void
    {
        abort_unless($usuario->can('nomina.recibos.crear') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $rectificacion->colaborador, 'incapacidades'), 403);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cadenas
     * @return array<string, mixed>
     */
    private function datosExpediente(Colaborador $colaborador, array $cadenas, User $usuario): array
    {
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
        $periodo = $this->quincenas->periodo($this->consulta->hoy());
        $resumen = $this->incidencias->resumen($colaborador, $periodo['inicio'], $periodo['fin']);

        return [
            'colaborador' => ['id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'puesto' => $colaborador->puesto?->nombre, 'sucursal' => $colaborador->sucursalPrincipal?->nombre],
            'cadenas' => $cadenas,
            'impacto' => ['etiqueta' => $periodo['etiqueta'], 'dias_incapacidad' => $resumen['dias_incapacidad'], 'dias_naturales' => $resumen['dias_naturales']],
            'regla' => $this->incidencias->reglaParaPantalla(),
            'puede' => ['subir' => $usuario->can('incapacidades.registrar'), 'corregir' => $usuario->can('incapacidades.corregir'), 'descargar' => $usuario->can('incapacidades.descargar')],
        ];
    }

    /**
     * @return array{clave: string, inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}
     */
    private function periodoImpacto(Request $request): array
    {
        $clave = $request->query('periodo');

        return is_string($clave) && preg_match('/^\d{4}-\d{2}-[12]$/', $clave) === 1
            ? $this->quincenas->periodoPorClave($clave)
            : $this->quincenas->periodo($this->consulta->hoy());
    }

    /**
     * @return array<string, mixed>
     */
    private function filtros(Request $request): array
    {
        $filtros = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
            'tipo' => ['nullable', Rule::enum(TipoIncapacidad::class)],
            'sucursal_id' => ['nullable', 'integer'],
            'estatus' => ['nullable', 'in:activa,concluida,cancelada'],
            'q' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer'],
        ]);

        return array_filter($filtros, fn ($v): bool => $v !== null);
    }

    /**
     * @return array<int, array{id: int, nombre: string}>
     */
    private function sucursales(User $usuario): array
    {
        $ids = $this->consulta->visibles($usuario)->pluck('sucursal_id')->filter()->unique();

        return Sucursal::query()->whereIn('id', $ids)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre])->values()->all();
    }
}
