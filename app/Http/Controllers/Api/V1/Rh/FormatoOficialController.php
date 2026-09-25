<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\GenerarFormatoOficialRequest;
use App\Http\Requests\Rh\GuardarCamposFormatoRequest;
use App\Http\Requests\Rh\NuevaVersionFormatoRequest;
use App\Http\Requests\Rh\RefinarAnalisisFormatoRequest;
use App\Http\Requests\Rh\StorePlantillaOficialRequest;
use App\Models\Colaborador;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\OfficialFormatVersion;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\Analisis\AnalisisPlantillaService;
use App\Services\Formatos\FormatoOficialPresenter;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Formatos\OfficialFormatCatalogoService;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Formatos\PlantillaOficialService;
use App\Services\Formatos\Variables\CatalogoVariablesFormato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plantillas oficiales para la app móvil de RH (docs/API_MOVIL.md →
 * "Formatos oficiales"). Mismos servicios, Form Requests y Policy que el
 * panel web (Rh\FormatoOficialController / Rh\PlantillaOficialController):
 * catálogo, variables, preparar/previsualizar/generar, documentos
 * generados y administración de versiones.
 */
class FormatoOficialController extends Controller
{
    public function __construct(
        private readonly OfficialFormatCatalogoService $catalogo,
        private readonly GeneradorFormatoService $generador,
        private readonly PlantillaOficialService $plantillas,
        private readonly AnalisisPlantillaService $analisis,
        private readonly FormatoOficialPresenter $presenter,
        private readonly OfficialFormatStorageService $storage,
        private readonly DocumentoStorageService $expediente,
        private readonly CatalogoVariablesFormato $variables,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OfficialFormat::class);

        return response()->json([
            'data' => $this->catalogo->listar([
                'tipo' => $request->string('tipo')->toString() ?: null,
                'busqueda' => $request->string('q')->toString() ?: null,
                'archivados' => $request->boolean('archivados'),
                'solo_listos' => $request->boolean('solo_listos'),
                'aplica_a' => $request->string('aplica_a')->toString() ?: null,
            ]),
        ]);
    }

    public function variables(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OfficialFormat::class);

        return response()->json([
            'data' => $this->variables->agrupadas($request->user()->can('formatos_oficiales.datos_salariales')),
            'formatos' => CatalogoVariablesFormato::FORMATOS,
        ]);
    }

    public function show(Request $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('view', $formato);
        $formato->load('versiones');

        return response()->json([
            'data' => [
                ...$this->catalogo->item($formato->loadCount('generaciones')),
                'versiones' => $formato->versiones->map(fn (OfficialFormatVersion $v) => $this->presenter->version($v, api: true))->values(),
            ],
        ]);
    }

    public function preparar(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('generar', $formato);
        [$datos, $manuales] = GeneradorFormatoService::entrada($request->validated());
        $r = $this->generador->prepararPara($formato, $request->user(), $datos, $manuales);
        $usados = $this->generador->contextosQueUsa($r['preparacion']['version']);

        return response()->json(['data' => $this->presenter->preparacion($r['preparacion'], [
            'contextos' => ['usados' => $usados, 'opciones' => $this->generador->opcionesContexto($r['sujeto'], $usados)],
            'puede_guardar_en_expediente' => $r['sujeto'] instanceof Colaborador,
        ])]);
    }

    public function vistaPrevia(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('generar', $formato);
        [$datos, $manuales] = GeneradorFormatoService::entrada($request->validated());
        $r = $this->generador->prepararPara($formato, $request->user(), $datos, $manuales);

        return response()->json(['data' => $this->presenter->preparacion($r['preparacion'], [
            'pdf_base64' => base64_encode($this->generador->vistaPrevia($r['preparacion'])),
        ])]);
    }

    public function generar(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('generar', $formato);
        [$datos, $manuales] = GeneradorFormatoService::entrada($request->validated());
        $r = $this->generador->prepararPara($formato, $request->user(), $datos, $manuales);
        $generacion = $this->generador->generar($r['preparacion'], $r['contexto'], $request->user(), $request->boolean('guardar_en_expediente', true));

        return response()->json(['data' => $this->presenter->generacion($generacion, api: true)], 201);
    }

    public function generados(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OfficialFormat::class);
        $usuario = $request->user();

        $pagina = OfficialFormatGeneration::query()
            ->where(fn ($q) => $q->whereNull('colaborador_id')
                ->orWhereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('colaboradores.id')))
            ->when($request->integer('formato_id'), fn ($q, int $id) => $q->where('official_format_id', $id))
            ->when($request->integer('colaborador_id'), fn ($q, int $id) => $q->where('colaborador_id', $id))
            ->latest()
            ->paginate(min(50, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (OfficialFormatGeneration $g) => $this->presenter->generacion($g, api: true))->values(),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    public function descargar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        return $this->archivoGeneracion($request, $generacion, 'attachment');
    }

    public function ver(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        return $this->archivoGeneracion($request, $generacion, 'inline');
    }

    // --- Administración de plantillas ------------------------------------------

    public function store(StorePlantillaOficialRequest $request): JsonResponse
    {
        $descripcion = $request->validated('descripcion');
        $formato = $this->plantillas->crear([
            'nombre' => (string) $request->validated('nombre'),
            'tipo' => (string) $request->validated('tipo'),
            'aplica_a' => (string) $request->validated('aplica_a'),
            'empresa_id' => $request->validated('empresa_id') !== null ? (int) $request->validated('empresa_id') : null,
            'descripcion' => is_string($descripcion) ? $descripcion : null,
        ], $request->file('archivo'), $request->user());

        return $this->show($request, $formato)->setStatusCode(201);
    }

    public function nuevaVersion(NuevaVersionFormatoRequest $request, OfficialFormat $formato): JsonResponse
    {
        $version = $this->plantillas->nuevaVersion($formato, $request->file('archivo'), $request->user(), $request->validated('notas'));

        return response()->json(['data' => $this->presenter->version($version, api: true)], 201);
    }

    public function guardarCampos(GuardarCamposFormatoRequest $request, OfficialFormatVersion $version): JsonResponse
    {
        $campos = [];

        foreach ((array) $request->validated('campos', []) as $campo) {
            if (is_array($campo)) {
                $campos[] = $campo;
            }
        }

        $version = $this->plantillas->guardarCampos($version, $campos, $request->user());

        return response()->json(['data' => $this->presenter->version($version, api: true)]);
    }

    public function refinarAnalisis(RefinarAnalisisFormatoRequest $request, OfficialFormatVersion $version): JsonResponse
    {
        $bloques = array_map(fn (array $b) => [
            'pagina' => (int) $b['pagina'],
            'texto' => (string) $b['texto'],
            'x' => (float) $b['x'],
            'y' => (float) $b['y'],
            'ancho' => (float) $b['ancho'],
            'alto' => (float) $b['alto'],
            'confianza' => 0.95,
        ], array_values((array) $request->validated('bloques')));

        $this->analisis->refinar($version, $bloques);

        return response()->json(['data' => $this->presenter->analisis($version->refresh())]);
    }

    public function publicar(Request $request, OfficialFormatVersion $version): JsonResponse
    {
        $this->authorize('versionar', $version->formato);

        return response()->json(['data' => $this->presenter->version($this->plantillas->publicar($version, $request->user()), api: true)]);
    }

    public function descartar(Request $request, OfficialFormatVersion $version): JsonResponse
    {
        $this->authorize('versionar', $version->formato);
        $this->plantillas->descartarBorrador($version, $request->user());

        return response()->json(['message' => 'Borrador descartado.']);
    }

    public function archivar(Request $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('archivar', $formato);
        $this->plantillas->archivar($formato, $request->user());

        return response()->json(['message' => 'Formato archivado.']);
    }

    public function reactivar(Request $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('archivar', $formato);
        $this->plantillas->reactivar($formato, $request->user());

        return response()->json(['message' => 'Formato reactivado.']);
    }

    public function base(Request $request, OfficialFormatVersion $version): StreamedResponse
    {
        $this->authorize('view', $version->formato);
        abort_if($version->base_path === null, 404);

        return $this->storage->respuesta($version->base_path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="formato-v'.$version->numero.'.pdf"',
        ]);
    }

    private function archivoGeneracion(Request $request, OfficialFormatGeneration $generacion, string $disposicion): StreamedResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('formatos_oficiales.descargar'), 403);

        if ($generacion->colaborador !== null) {
            abort_unless($this->alcance->puedeVerExpediente($usuario, $generacion->colaborador), 404);
        }

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposicion, str_replace(['"', '\\', '/'], '', $generacion->generated_name)),
        ];

        return $generacion->generated_disk === config('formatos_oficiales.disk')
            ? $this->storage->respuesta($generacion->generated_path, $headers)
            : $this->expediente->respuesta($generacion->generated_path, $headers);
    }
}
