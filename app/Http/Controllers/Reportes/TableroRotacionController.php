<?php

namespace App\Http\Controllers\Reportes;

use App\Enums\MotorPdf;
use App\Exports\RotacionExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bajas\GuardarMetaRotacionRequest;
use App\Models\Baja;
use App\Models\CausalAnalitica;
use App\Models\CausalRotacion;
use App\Models\ImportacionExcel;
use App\Models\User;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use App\Services\Rotacion\BajasHistoricasImportService;
use App\Services\Rotacion\CorreccionRotacionService;
use App\Services\Rotacion\FiltrosRotacion;
use App\Services\Rotacion\HistoricoRotacionService;
use App\Services\Rotacion\RotacionAnalisisService;
use App\Services\Rotacion\RotacionExportService;
use App\Services\Rotacion\RotacionMetaService;
use App\Services\Rotacion\RotacionMotorService;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes → Rotación de personal (docs/ROTACION.md). Una sola pantalla con
 * cinco áreas conectadas (resumen, tendencias, causales, sucursales e
 * histórico) sobre el motor único RotacionMotorService. Cada sección es una
 * prop perezosa: una recarga parcial (paginar el histórico, abrir una causal)
 * solo recalcula lo que pide. Exportaciones con los MISMOS filtros.
 *
 * Permisos: ver = bajas.tablero.ver (alcance por sucursal); personas y
 * exportar el detalle = bajas.ver o bajas.gestionar; corregir y conciliar =
 * bajas.gestionar; cargar histórico = rotacion.cargar.
 */
class TableroRotacionController extends Controller
{
    public function __construct(
        private readonly RotacionMotorService $motor,
        private readonly RotacionAnalisisService $analisis,
        private readonly HistoricoRotacionService $historico,
        private readonly CorreccionRotacionService $correcciones,
        private readonly RotacionExportService $exportes,
        private readonly BajasHistoricasImportService $importador,
        private readonly BajaConsultaService $bajas,
        private readonly RotacionMetaService $metas,
        private readonly PdfRendererFactory $pdf,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('verTablero', Baja::class);
        $usuario = $request->user();
        $f = $this->motor->filtros($usuario, $request->query());
        $verPersonas = $this->puedeVerPersonas($usuario);
        $datos = fn (): array => $this->exportes->datos($usuario, $f);

        return Inertia::render('Reportes/Rotacion/Index', [
            'pestanas' => $this->bajas->pestanas($usuario),
            'filtros' => $f->aArray(),
            'desglose' => $this->desglose($request),
            'opciones' => fn (): array => $this->analisis->opciones($this->motor->sucursalesVisibles($usuario)),
            'mesInicioSistema' => $this->motor->mesInicioSistema()->format('Y-m'),
            'resumen' => fn (): array => $datos()['resumen'],
            'tendencia' => fn (): array => $datos()['tendencia'],
            'causales' => fn (): array => $datos()['causales'],
            'antiguedad' => fn (): array => $datos()['antiguedad'],
            'sucursales' => fn (): array => $datos()['sucursales'],
            'matriz' => fn (): array => $datos()['matriz'],
            'hallazgos' => fn (): array => $datos()['hallazgos'],
            'calidad' => fn (): array => $datos()['calidad'],
            'historico' => fn (): ?array => $verPersonas ? $this->paginaHistorico($request, $usuario, $f) : null,
            'conciliacion' => Inertia::optional(fn (): array => $usuario->can(CorreccionRotacionService::PERMISO) ? $this->analisis->posiblesDuplicados($f)->all() : []),
            'catalogo' => Inertia::optional(fn (): array => $this->catalogo($usuario)),
            'importacion' => $usuario->can('rotacion.cargar') && $request->integer('importacion') > 0
                ? ImportacionExcel::query()->where('tipo', BajasHistoricasImportService::TIPO)->whereKey($request->integer('importacion'))->first()?->only(['id', 'nombre_archivo', 'estado', 'total_filas', 'filas_aplicadas', 'filas_revision', 'filas_sin_cambios', 'filas_error', 'detalle'])
                : null,
            'metas' => fn (): array => $this->metas->panel($f->hasta->startOfMonth()),
            'puede' => [
                'ver_personas' => $verPersonas,
                'corregir' => $usuario->can(CorreccionRotacionService::PERMISO),
                'editar_metas' => $usuario->can('bajas.gestionar'),
                'cargar_historico' => $usuario->can('rotacion.cargar'),
                'ver_historico_excel' => $usuario->can('rotacion.ver'),
                'exportar_detalle' => $verPersonas,
            ],
        ]);
    }

    /** Bitácora de correcciones de un evento (diálogo «Ver historial de cambios»). */
    public function bitacora(Request $request, string $fuente, int $id): JsonResponse
    {
        $this->authorize('verTablero', Baja::class);
        abort_unless($this->puedeVerPersonas($request->user()) && in_array($fuente, ['sistema', 'historico'], true), 403);
        $visible = $this->motor->eventos($this->alcance($request->user()), false)->firstWhere('clave', sprintf('%s:%d', $fuente, $id));
        abort_if($visible === null, 404);

        return response()->json(['correcciones' => $this->historico->bitacora($fuente, $id)]);
    }

    public function corregirCausal(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'fuente' => ['required', 'in:sistema,historico'],
            'id' => ['required', 'integer'],
            'causal_analitica_id' => ['required', 'integer'],
            'comentario' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['comentario.required' => 'Escribe por qué se corrige: queda en la bitácora.', 'comentario.min' => 'Describe un poco más el motivo de la corrección.']);

        $this->correcciones->corregirCausal($request->user(), $datos['fuente'], (int) $datos['id'], (int) $datos['causal_analitica_id'], $datos['comentario'], $this->alcance($request->user()));

        return back()->with('toast', ['type' => 'success', 'message' => 'Causal corregida. Las estadísticas ya la consideran.']);
    }

    public function completarFecha(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'id' => ['required', 'integer'],
            'fecha_ingreso' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'comentario' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['fecha_ingreso.required' => 'Indica la fecha de ingreso.', 'comentario.required' => 'Escribe de dónde sale el dato: queda en la bitácora.']);

        $this->correcciones->completarFechaIngreso($request->user(), (int) $datos['id'], $datos['fecha_ingreso'], $datos['comentario'], $this->alcance($request->user()));

        return back()->with('toast', ['type' => 'success', 'message' => 'Fecha de ingreso completada.']);
    }

    public function vincular(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'historica_id' => ['required', 'integer'],
            'baja_id' => ['required', 'integer'],
            'comentario' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->correcciones->vincular($request->user(), (int) $datos['historica_id'], (int) $datos['baja_id'], $datos['comentario'], $this->alcance($request->user()));

        return back()->with('toast', ['type' => 'success', 'message' => 'Registros ligados: la baja ya no se cuenta dos veces.']);
    }

    public function estadoHistorica(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'historica_id' => ['required', 'integer'],
            'estado' => ['required', 'in:valida,descartada'],
            'comentario' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->correcciones->cambiarEstadoHistorica($request->user(), (int) $datos['historica_id'], $datos['estado'], $datos['comentario'], $this->alcance($request->user()));

        return back()->with('toast', ['type' => 'success', 'message' => $datos['estado'] === 'valida' ? 'Baja histórica restablecida.' : 'Baja histórica excluida (se conserva en la bitácora).']);
    }

    public function mapearCausal(Request $request, CausalRotacion $causal): RedirectResponse
    {
        $datos = $request->validate([
            'causal_analitica_id' => ['nullable', 'integer'],
            'comentario' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $afectadas = $this->correcciones->mapearCausal($request->user(), $causal, isset($datos['causal_analitica_id']) ? (int) $datos['causal_analitica_id'] : null, $datos['comentario']);

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Homologación guardada: %d baja(s) del sistema se reclasificaron.', $afectadas)]);
    }

    public function guardarCategoria(Request $request, ?CausalAnalitica $categoria = null): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:160'],
            'clasificacion' => ['required', 'in:voluntaria,involuntaria,sin_clasificar'],
            'es_estructural' => ['boolean'],
            'activo' => ['boolean'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'comentario' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->correcciones->guardarCategoria($request->user(), $categoria, $datos, $datos['comentario']);

        return back()->with('toast', ['type' => 'success', 'message' => $categoria === null ? 'Causal homologada creada.' : 'Causal homologada actualizada.']);
    }

    public function guardarMeta(GuardarMetaRotacionRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('bajas.gestionar'), 403);
        $this->metas->registrar($request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Meta registrada. Los meses anteriores conservan la que tenían.']);
    }

    public function excel(Request $request): HttpResponse
    {
        $this->authorize('verTablero', Baja::class);
        $usuario = $request->user();
        $f = $this->motor->filtros($usuario, $request->query());
        $datos = $this->exportes->datos($usuario, $f);
        $detalle = $this->puedeVerPersonas($usuario) ? $this->historico->filas($usuario, $this->historico->filtrados($f, $request->query()), false) : null;

        return Excel::download(new RotacionExport($datos, $detalle), sprintf('rotacion-%s.xlsx', now()->format('Y-m-d')));
    }

    public function pdf(Request $request): HttpResponse|RedirectResponse
    {
        return $this->renderPdf($request, 'ejecutivo');
    }

    public function pdfDetallado(Request $request): HttpResponse|RedirectResponse
    {
        return $this->renderPdf($request, 'detallado');
    }

    public function formatoHistorico(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('rotacion.cargar'), 403);
        $libro = $this->importador->plantillaExcel();

        return response()->streamDownload(fn () => (new Xlsx($libro))->save('php://output'), 'formato-bajas-historicas.xlsx');
    }

    public function analizarHistorico(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('rotacion.cargar'), 403);
        $datos = $request->validate(
            ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']],
            ['archivo.required' => 'Selecciona el archivo de Excel.', 'archivo.mimes' => 'El archivo debe ser .xlsx.', 'archivo.max' => 'El archivo no debe pasar de 10 MB.'],
        );

        $importacion = $this->importador->analizar($datos['archivo'], $request->user());

        return to_route('reportes.rotacion.tablero.index', ['seccion' => 'historico', 'importacion' => $importacion->id])
            ->with('toast', ['type' => 'success', 'message' => 'Vista previa lista. Nada se ha guardado todavía.']);
    }

    public function aplicarHistorico(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('rotacion.cargar'), 403);
        $datos = $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:400']],
            ['motivo.required' => 'Indica el motivo de la carga.', 'motivo.min' => 'Describe un poco más el motivo.'],
        );

        $guardadas = $this->importador->aplicar($importacion, $datos['motivo'], $request->user());

        return to_route('reportes.rotacion.tablero.index', ['seccion' => 'historico'])
            ->with('toast', ['type' => 'success', 'message' => sprintf('%d baja(s) histórica(s) cargada(s).', $guardadas)]);
    }

    private function renderPdf(Request $request, string $tipo): HttpResponse|RedirectResponse
    {
        $this->authorize('verTablero', Baja::class);
        $usuario = $request->user();
        $f = $this->motor->filtros($usuario, $request->query());
        $datos = $this->exportes->datos($usuario, $f);
        $detalle = $tipo === 'detallado' && $this->puedeVerPersonas($usuario)
            ? $this->historico->filas($usuario, $this->historico->filtrados($f, $request->query()), false)
            : null;

        try {
            $resultado = $this->pdf->renderizar(
                MotorPdf::Browsershot,
                view($tipo === 'ejecutivo' ? 'pdf.rotacion-ejecutivo' : 'pdf.rotacion-detallado', ['datos' => $datos, 'detalle' => $detalle])->render(),
                new OpcionesPdf(tamano: 'letter', orientacion: $tipo === 'ejecutivo' ? 'landscape' : 'portrait', numerarPaginas: true, textoPie: 'MR. LANA PEOPLE · Rotación de personal'),
            );
        } catch (PdfRendererException $e) {
            Log::warning('Rotación: no se pudo generar el PDF.', ['tipo' => $tipo, 'error' => $e->getMessage()]);

            return back()->with('toast', ['type' => 'error', 'message' => 'No se pudo generar el PDF. Intenta de nuevo o descarga el Excel.']);
        }

        return response($resultado['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="rotacion-%s-%s.pdf"', $tipo, now()->format('Y-m-d')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginaHistorico(Request $request, User $usuario, FiltrosRotacion $f): array
    {
        $pagina = $this->historico->pagina($usuario, $f, $request->query(), Paginacion::porPagina(), max(1, $request->integer('page', 1)));

        return [
            'data' => $pagina->items(),
            'current_page' => $pagina->currentPage(),
            'last_page' => $pagina->lastPage(),
            'per_page' => $pagina->perPage(),
            'total' => $pagina->total(),
            'from' => $pagina->firstItem(),
            'to' => $pagina->lastItem(),
        ];
    }

    /**
     * Desglose con el que se llegó desde una gráfica: acota solo el histórico.
     *
     * @return array<string, string|null>
     */
    private function desglose(Request $request): array
    {
        $texto = fn (string $clave): ?string => is_scalar($request->query($clave)) && $request->query($clave) !== '' ? (string) $request->query($clave) : null;

        return [
            'seccion' => $texto('seccion'),
            'd_mes' => $texto('d_mes'),
            'd_sucursal' => $texto('d_sucursal'),
            'd_causal' => $texto('d_causal'),
            'd_rango' => $texto('d_rango'),
            'd_clasificacion' => $texto('d_clasificacion'),
            'rapido' => $texto('rapido'),
            'q' => $texto('q'),
            'orden' => $texto('orden'),
            'dir' => $texto('dir'),
        ];
    }

    /**
     * Catálogo para homologar: causales homologadas y el mapeo de cada causal
     * del catálogo administrativo (con cuántas bajas se reclasificarían).
     *
     * @return array<string, mixed>
     */
    private function catalogo(User $usuario): array
    {
        if (! $usuario->can(CorreccionRotacionService::PERMISO)) {
            return ['categorias' => [], 'mapeo' => []];
        }

        return [
            'categorias' => array_values(CausalAnalitica::query()->ordenadas()->withCount('causalesRotacion')->get()->map(fn (CausalAnalitica $c): array => [
                'id' => $c->id,
                'clave' => $c->clave,
                'nombre' => $c->nombre,
                'clasificacion' => $c->clasificacion->value,
                'clasificacion_etiqueta' => $c->clasificacion->etiqueta(),
                'es_estructural' => $c->es_estructural,
                'descripcion' => $c->descripcion,
                'activo' => $c->activo,
                'causales_catalogo' => (int) $c->getAttribute('causales_rotacion_count'),
            ])->all()),
            'mapeo' => array_values(CausalRotacion::query()->delCatalogo()->ordenadas()->get()->map(fn (CausalRotacion $c): array => [
                'id' => $c->id,
                'nombre' => $c->etiquetaConClave(),
                'forma' => $c->forma()->etiqueta(),
                'causal_analitica_id' => $c->causal_analitica_id,
                'bajas' => $this->correcciones->afectadasPorMapeo($c),
            ])->all()),
        ];
    }

    private function alcance(User $usuario): FiltrosRotacion
    {
        $f = $this->motor->filtros($usuario, []);

        return $f->conRango($f->desde->subYears(50), $f->hasta);
    }

    private function puedeVerPersonas(User $usuario): bool
    {
        return $usuario->can('bajas.ver') || $usuario->can('bajas.gestionar');
    }
}
