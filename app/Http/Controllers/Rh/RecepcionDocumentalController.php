<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecepcionDocumental\MarcarRecepcionRequest;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalPdf;
use App\Models\RecepcionDocumentalRenglon;
use App\Services\RecepcionDocumental\CatalogoReglasRecepcion;
use App\Services\RecepcionDocumental\MaterializadorCorteService;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalPdfService;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recepción documental RH (web): cortes mensuales de originales firmados
 * recibidos por paquetería. Delgado: autoriza (Policies), valida y delega
 * en RecepcionDocumentalService — el mismo que usa la API móvil
 * (Api\V1\Rh\RecepcionDocumentalController).
 */
class RecepcionDocumentalController extends Controller
{
    private const FILTROS = ['sucursal_id', 'regla', 'tipo_documento', 'colaborador', 'estado', 'recibido'];

    public function __construct(
        private readonly RecepcionDocumentalService $servicio,
        private readonly MaterializadorCorteService $materializador,
        private readonly RecepcionDocumentalPdfService $pdfs,
        private readonly CatalogoReglasRecepcion $catalogo,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RecepcionDocumentalCorte::class);
        $usuario = $request->user();
        $cortes = $this->servicio->listarCortes($usuario);
        $metricas = $this->servicio->metricasPorCorte($usuario, array_values(array_map(fn (RecepcionDocumentalCorte $c): int => $c->id, $cortes->items())));
        $actual = PeriodoRecepcion::actual();

        return Inertia::render('Rh/RecepcionDocumental/Index', [
            'cortes' => $cortes->through(fn (RecepcionDocumentalCorte $c): array => $this->servicio->filaCorte($c, $metricas[$c->id] ?? RecepcionDocumentalService::metricasVacias())),
            'periodoActual' => ['clave' => $actual->clave(), 'etiqueta' => ucfirst($actual->etiqueta())],
            'puedeGenerar' => $usuario->can('generar', RecepcionDocumentalCorte::class),
            'reglas' => $this->catalogo->resumen(),
        ]);
    }

    /** Crea (si falta) y calcula el corte de un mes; idempotente. */
    public function generar(Request $request): RedirectResponse
    {
        $this->authorize('generar', RecepcionDocumentalCorte::class);
        $datos = $request->validate(['periodo' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/']], ['periodo.regex' => 'Elige un mes válido (AAAA-MM).']);

        if (! PeriodoRecepcion::esClaveValida($datos['periodo'])) {
            return back()->withErrors(['periodo' => 'Elige un mes válido (AAAA-MM).']);
        }

        $periodo = PeriodoRecepcion::desdeClave($datos['periodo']);
        $corteExistente = RecepcionDocumentalCorte::query()->where('periodo', $periodo->clave())->first();

        if ($corteExistente !== null) {
            $this->authorize('generar', $corteExistente);
        }

        ['corte' => $corte, 'conteo' => $conteo] = $this->servicio->generar($periodo, $request->user());

        return to_route('rh.recepcion-documental.show', $corte)->with('toast', [
            'type' => 'success',
            'message' => sprintf('Corte de %s calculado: %d esperado(s) nuevo(s).', $periodo->etiqueta(), $conteo['nuevos'] ?? 0),
        ]);
    }

    public function show(Request $request, RecepcionDocumentalCorte $corte): Response
    {
        $this->authorize('view', $corte);
        $usuario = $request->user();
        $corte->loadMissing(['cerradoPor:id,name', 'reabiertoPor:id,name']);
        $filtros = $request->only(self::FILTROS);

        return Inertia::render('Rh/RecepcionDocumental/Show', [
            'corte' => $this->servicio->filaCorte($corte, $this->servicio->metricas($corte, $usuario)),
            'metricasFiltradas' => $this->servicio->metricas($corte, $usuario, $filtros),
            'agrupaciones' => $this->servicio->agrupaciones($corte, $usuario),
            'renglones' => $this->servicio->renglones($corte, $usuario, $filtros)
                ->through(fn (RecepcionDocumentalRenglon $r): array => $this->servicio->filaRenglon($r)),
            'opciones' => $this->servicio->opcionesFiltros($corte, $usuario),
            'filtros' => $this->servicio->normalizarFiltros($filtros),
            'permisos' => $this->servicio->permisosDe($usuario, $corte),
            'pdfs' => $this->pdfs->versiones($corte),
            'historial' => $this->servicio->historialCorte($corte),
        ]);
    }

    public function materializar(Request $request, RecepcionDocumentalCorte $corte): RedirectResponse
    {
        $this->authorize('generar', $corte);
        $conteo = $this->materializador->materializar($corte, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf(
            'Corte actualizado: %d nuevo(s), %d pasaron a «No aplica», %d reactivado(s).',
            $conteo['nuevos'],
            $conteo['no_aplica'],
            $conteo['reactivados'],
        )]);
    }

    public function cerrar(Request $request, RecepcionDocumentalCorte $corte): RedirectResponse
    {
        $this->authorize('cerrar', $corte);
        $datos = $request->validate(['observaciones' => ['nullable', 'string', 'max:2000']]);
        $this->servicio->cerrar($corte, $request->user(), $datos['observaciones'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Corte cerrado. Ya no se recalcula.']);
    }

    public function reabrir(Request $request, RecepcionDocumentalCorte $corte): RedirectResponse
    {
        $this->authorize('reabrir', $corte);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:2000']], ['motivo.required' => 'Escribe el motivo para reabrir el corte.']);
        $this->servicio->reabrir($corte, $request->user(), $datos['motivo']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Corte reabierto. El motivo quedó en el historial.']);
    }

    /** Genera el PDF operativo (versionado y guardado) y lo muestra. */
    public function pdf(Request $request, RecepcionDocumentalCorte $corte): StreamedResponse
    {
        $this->authorize('descargarPdf', $corte);
        $variante = RecepcionDocumentalPdfService::varianteValida($request->string('variante')->toString());
        $registro = $this->pdfs->generar($corte, $request->user(), $variante, $request->only(self::FILTROS));

        return $this->pdfs->respuesta($registro);
    }

    /** Una versión anterior del PDF (nunca se sobreescribe). */
    public function verPdf(Request $request, RecepcionDocumentalCorte $corte, RecepcionDocumentalPdf $pdf): StreamedResponse
    {
        $this->authorize('descargarPdf', $corte);
        abort_unless($pdf->corte_id === $corte->id, 404);

        return $this->pdfs->respuesta($pdf);
    }

    /** Palomeo rápido desde la lista (JSON: la pantalla actualiza en optimista). */
    public function marcar(MarcarRecepcionRequest $request, RecepcionDocumentalRenglon $renglon): JsonResponse
    {
        $actualizado = $this->servicio->marcar($renglon, $request->user(), $request->estado(), $request->datos());
        $corte = $actualizado->corte->refresh();

        return response()->json([
            'renglon' => $this->servicio->filaRenglon($actualizado),
            'metricas' => $this->servicio->metricas($corte, $request->user()),
            'estado_corte' => ['value' => $corte->estado->value, 'label' => $corte->estado->etiqueta()],
        ]);
    }

    public function evidencia(Request $request, RecepcionDocumentalRenglon $renglon): JsonResponse
    {
        $this->authorize('subirEvidencia', $renglon);
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']], ['archivo.required' => 'Elige el archivo de evidencia.']);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);
        $actualizado = $this->servicio->subirEvidencia($renglon, $archivo, $request->user());

        return response()->json(['renglon' => $this->servicio->filaRenglon($actualizado->load(['recibidoPor:id,name', 'documento:id,estado_flujo,titulo,clave_plantilla']))]);
    }

    public function verEvidencia(RecepcionDocumentalRenglon $renglon): StreamedResponse
    {
        $this->authorize('view', $renglon);

        return $this->servicio->descargarEvidencia($renglon);
    }

    public function historial(RecepcionDocumentalRenglon $renglon): JsonResponse
    {
        $this->authorize('view', $renglon);

        return response()->json(['data' => $this->servicio->historial($renglon)]);
    }
}
