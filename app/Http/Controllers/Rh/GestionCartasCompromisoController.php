<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartasCompromiso\GenerarCartaCompromisoRequest;
use App\Http\Requests\CartasCompromiso\SubirCartaCompromisoFirmadaRequest;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Services\Expedientes\CartaCompromisoFirmaService;
use App\Services\Expedientes\CartaCompromisoService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\GestionCartasCompromisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo «Cartas compromiso» (web). Delgado: la autorización vive en
 * CartaCompromisoPolicy y las reglas en GestionCartasCompromisoService /
 * CartaCompromisoService / CartaCompromisoFirmaService.
 */
class GestionCartasCompromisoController extends Controller
{
    public function __construct(
        private readonly GestionCartasCompromisoService $gestion,
        private readonly CartaCompromisoService $cartas,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', CartaCompromiso::class);
        $usuario = $request->user();

        $listado = $this->gestion->listado($usuario, $request->only(['pestana', 'busqueda', 'per_page']))->withQueryString();
        $listado->getCollection()->transform(fn (CartaCompromiso $c): array => $this->gestion->presentar($c));

        return Inertia::render('Rh/CartasCompromiso/Index', [
            'cartas' => $listado,
            'indicadores' => $this->gestion->indicadores($usuario),
            'filtros' => [
                'pestana' => in_array($request->query('pestana'), GestionCartasCompromisoService::PESTANAS, true) ? (string) $request->query('pestana') : 'todas',
                'busqueda' => (string) $request->query('busqueda', ''),
            ],
            'puedeGenerar' => $usuario->can(GestionCartasCompromisoService::PERMISO_GENERAR),
            'puedeDescargar' => $usuario->can(GestionCartasCompromisoService::PERMISO_DESCARGAR),
            'reglas' => $this->reglas(),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can(GestionCartasCompromisoService::PERMISO_GENERAR), 403);

        // ?colaborador=ID (p. ej. desde el expediente): se preselecciona si
        // está en su alcance; si no, se ignora en silencio.
        $inicial = null;
        $id = $request->integer('colaborador');

        if ($id > 0) {
            $colaborador = Colaborador::query()->where('id', $id)->first();

            if ($colaborador !== null && $usuario->can('generar', [CartaCompromiso::class, $colaborador])) {
                $inicial = $this->gestion->preparar($colaborador)['colaborador'];
            }
        }

        return Inertia::render('Rh/CartasCompromiso/Nueva', [
            'colaboradorInicial' => $inicial,
            'reglas' => $this->reglas(),
        ]);
    }

    /** Buscador: lista completa al enfocar, filtra al escribir. */
    public function colaboradores(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can(GestionCartasCompromisoService::PERMISO_GENERAR), 403);

        return response()->json(['data' => $this->gestion->buscarColaboradores($usuario, (string) $request->query('q', ''))]);
    }

    public function preparar(Colaborador $colaborador): JsonResponse
    {
        $this->authorize('generar', [CartaCompromiso::class, $colaborador]);

        return response()->json(['data' => $this->gestion->preparar($colaborador)]);
    }

    /** PDF de exactamente la carta que se generaría (no guarda nada). */
    public function vistaPrevia(GenerarCartaCompromisoRequest $request, Colaborador $colaborador): Response
    {
        $this->authorize('generar', [CartaCompromiso::class, $colaborador]);

        return $this->pdfInline($this->gestion->vistaPrevia($colaborador, $request->documentos()), 'Vista previa carta compromiso.pdf');
    }

    public function store(GenerarCartaCompromisoRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('generar', [CartaCompromiso::class, $colaborador]);

        $carta = $this->gestion->generar($colaborador, $request->documentos(), $request->user());

        return to_route('rh.cartas-compromiso.show', $carta)->with('toast', [
            'type' => 'success',
            'message' => sprintf('Carta %s generada y guardada en el expediente de %s. Imprímela para que la firme y sube el escaneo firmado.', (string) $carta->folio, $colaborador->nombreCompleto()),
        ]);
    }

    public function show(Request $request, CartaCompromiso $carta): InertiaResponse
    {
        $this->authorize('view', $carta);

        // Pone al día cumplida/vencida antes de mostrarla.
        $colaborador = $carta->colaborador()->withTrashed()->first();

        if ($colaborador !== null) {
            $this->cartas->sincronizarSinFallar($colaborador);
        }

        return Inertia::render('Rh/CartasCompromiso/Show', [
            'carta' => $this->gestion->detalle($carta->refresh(), $request->user()),
        ]);
    }

    public function pdf(Request $request, CartaCompromiso $carta): Response
    {
        $this->authorize('descargar', $carta);

        return $this->pdfInline($this->cartas->pdf($carta, $request->user()), sprintf('Carta compromiso %s.pdf', (string) $carta->folio));
    }

    public function subirFirmada(SubirCartaCompromisoFirmadaRequest $request, CartaCompromiso $carta, CartaCompromisoFirmaService $firmas): RedirectResponse
    {
        $this->authorize('subirFirmada', $carta);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $firmas->subir($carta, $archivo, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Carta %s firmada: el escaneo quedó en el expediente.', (string) $carta->folio)]);
    }

    /** Escaneo firmado, inline (visor). */
    public function verFirmada(CartaCompromiso $carta, DocumentoStorageService $storage): StreamedResponse
    {
        $this->authorize('descargar', $carta);
        $documento = $carta->documentoFirmado;
        abort_if($documento === null, 404, 'Esta carta todavía no tiene su escaneo firmado.');

        return $storage->respuesta($documento->path, [
            'Content-Type' => (string) ($documento->mime ?? 'application/octet-stream'),
            'Content-Disposition' => sprintf('inline; filename="Carta compromiso firmada %s.%s"', (string) $carta->folio, (string) ($documento->extension ?? 'pdf')),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{max_documentos: int, dias_plazo: int, dias_por_vencer: int}
     */
    private function reglas(): array
    {
        return [
            'max_documentos' => $this->cartas->maxDocumentos(),
            'dias_plazo' => $this->cartas->diasPlazo(),
            'dias_por_vencer' => $this->gestion->diasPorVencer(),
        ];
    }

    private function pdfInline(string $pdf, string $nombre): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nombre),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
