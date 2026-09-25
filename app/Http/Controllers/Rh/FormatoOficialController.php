<?php

namespace App\Http\Controllers\Rh;

use App\Enums\AplicaFormato;
use App\Enums\TipoFormatoOficial;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\GenerarFormatoOficialRequest;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\FormatoOficialPresenter;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Formatos\OfficialFormatCatalogoService;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Formatos\Variables\CatalogoVariablesFormato;
use App\Services\Formatos\Variables\ContextoFormato;
use App\Services\Solicitudes\SolicitudFormatoOficialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plantillas oficiales — catálogo, generación y documentos generados
 * (docs/FORMATOS_OFICIALES.md). La administración de plantillas (subir,
 * mapear, versionar) vive en PlantillaOficialController; la lógica, en
 * App\Services\Formatos\*.
 *
 * @phpstan-import-type Preparacion from GeneradorFormatoService
 */
class FormatoOficialController extends Controller
{
    public function __construct(
        private readonly OfficialFormatCatalogoService $catalogo,
        private readonly GeneradorFormatoService $generador,
        private readonly OfficialFormatStorageService $storage,
        private readonly DocumentoStorageService $expediente,
        private readonly CatalogoVariablesFormato $variables,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudFormatoOficialService $formatoDeSolicitud,
        private readonly FormatoOficialPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OfficialFormat::class);

        $usuario = $request->user();
        $filtros = [
            'tipo' => $request->string('tipo')->toString() ?: null,
            'busqueda' => $request->string('busqueda')->toString() ?: null,
            'archivados' => $request->boolean('archivados'),
        ];

        return Inertia::render('Rh/FormatosOficiales/Index', [
            'formatos' => $this->catalogo->listar($filtros),
            'filtros' => $filtros,
            'categorias' => TipoFormatoOficial::opciones(),
            'aplicaA' => array_map(fn (AplicaFormato $a) => ['value' => $a->value, 'etiqueta' => $a->etiqueta()], AplicaFormato::cases()),
            'empresas' => Empresa::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'colaboradoresDisponibles' => $this->colaboradoresDisponibles($usuario),
            'candidatosDisponibles' => Candidato::query()->orderBy('nombre')->limit(300)->get(['id', 'nombre', 'apellidos']),
            'permisos' => $this->permisos($usuario),
        ]);
    }

    /**
     * Historial de documentos generados, dentro del alcance del usuario.
     */
    public function generados(Request $request): Response
    {
        $this->authorize('viewAny', OfficialFormat::class);
        $usuario = $request->user();

        $generaciones = OfficialFormatGeneration::query()
            ->with(['formato:id,nombre,tipo', 'colaborador:id,name,apellidos', 'candidato:id,nombre,apellidos', 'generadoPor:id,name', 'solicitud:id,folio'])
            ->where(fn ($q) => $q->whereNull('colaborador_id')
                ->orWhereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('colaboradores.id')))
            ->when($request->integer('formato_id'), fn ($q, int $id) => $q->where('official_format_id', $id))
            ->when($request->string('busqueda')->toString(), fn ($q, string $texto) => $q->where(fn ($s) => $s
                ->whereHas('colaborador', fn ($c) => $c->where('name', 'like', "%{$texto}%")->orWhere('apellidos', 'like', "%{$texto}%"))
                ->orWhereHas('candidato', fn ($c) => $c->where('nombre', 'like', "%{$texto}%")->orWhere('apellidos', 'like', "%{$texto}%"))))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (OfficialFormatGeneration $g) => $this->presenter->generacion($g));

        return Inertia::render('Rh/FormatosOficiales/Generados', [
            'generaciones' => $generaciones,
            'formatos' => OfficialFormat::query()->orderBy('nombre')->get(['id', 'nombre']),
            'filtros' => ['formato_id' => $request->integer('formato_id') ?: null, 'busqueda' => $request->string('busqueda')->toString()],
        ]);
    }

    /**
     * Catálogo de variables disponibles (lo que People sabe y puede poner
     * en un documento).
     */
    public function variables(Request $request): Response
    {
        $this->authorize('viewAny', OfficialFormat::class);

        return Inertia::render('Rh/FormatosOficiales/Variables', [
            'grupos' => $this->variables->agrupadas($request->user()->can('formatos_oficiales.datos_salariales')),
            'formatos' => CatalogoVariablesFormato::FORMATOS,
        ]);
    }

    /**
     * Qué falta, qué se pedirá a mano y de qué solicitud/préstamo/contrato
     * sale el documento, antes de previsualizar o generar.
     */
    public function preparar(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('generar', $formato);
        [$sujeto, $contexto, $preparacion] = $this->prepararDesde($request, $formato);
        $version = $preparacion['version'];

        return response()->json($this->presenter->preparacion($preparacion, [
            'contextos' => [
                'usados' => $this->generador->contextosQueUsa($version),
                'opciones' => $this->generador->opcionesContexto($sujeto, $this->generador->contextosQueUsa($version)),
            ],
            'puede_guardar_en_expediente' => $sujeto instanceof Colaborador,
        ]));
    }

    public function previsualizarGeneracion(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('generar', $formato);
        [, , $preparacion] = $this->prepararDesde($request, $formato);

        return response()->json($this->presenter->preparacion($preparacion, [
            'pdf_base64' => base64_encode($this->generador->vistaPrevia($preparacion)),
        ]));
    }

    public function generar(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        $this->authorize('generar', $formato);
        [, $contexto, $preparacion] = $this->prepararDesde($request, $formato);

        $generacion = $this->generador->generar($preparacion, $contexto, $request->user(), $request->boolean('guardar_en_expediente', true));

        return response()->json([
            'generacion' => [
                'id' => $generacion->id,
                'nombre' => $generacion->generated_name,
                'version' => $generacion->version_numero,
                'en_expediente' => $generacion->en_expediente,
                'descargar_url' => route('rh.formatos-oficiales.descargar', $generacion->id),
                'ver_url' => route('rh.formatos-oficiales.previsualizar', $generacion->id),
            ],
        ]);
    }

    public function descargar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        $this->autorizarGeneracion($request, $generacion);

        return $this->respuestaGeneracion($generacion, 'attachment');
    }

    /**
     * Igual que descargar(), pero inline (visor embebido).
     */
    public function previsualizar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        $this->autorizarGeneracion($request, $generacion);

        return $this->respuestaGeneracion($generacion, 'inline');
    }

    public function subirFirmado(Request $request, OfficialFormatGeneration $generacion): RedirectResponse
    {
        $this->autorizarGeneracion($request, $generacion);
        abort_unless($request->user()->can('formatos_oficiales.generar'), 403);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ]);

        $this->formatoDeSolicitud->archivarFirmado($generacion, $request->file('archivo'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento firmado archivado.']);
    }

    /**
     * @return array{0: Colaborador|Candidato, 1: ContextoFormato, 2: Preparacion}
     */
    private function prepararDesde(GenerarFormatoOficialRequest $request, OfficialFormat $formato): array
    {
        [$datos, $manuales] = GeneradorFormatoService::entrada($request->validated());
        $resultado = $this->generador->prepararPara($formato, $request->user(), $datos, $manuales);

        return [$resultado['sujeto'], $resultado['contexto'], $resultado['preparacion']];
    }

    private function respuestaGeneracion(OfficialFormatGeneration $generacion, string $disposicion): StreamedResponse
    {
        $nombre = str_replace(['"', '\\', '/'], '', $generacion->generated_name);
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => sprintf('%s; filename="%s"', $disposicion, $nombre)];

        if ($generacion->generated_disk === config('formatos_oficiales.disk')) {
            return $this->storage->respuesta($generacion->generated_path, $headers);
        }

        abort_unless(Storage::disk($generacion->generated_disk)->exists($generacion->generated_path), 404, 'El archivo no está disponible.');

        return $this->expediente->respuesta($generacion->generated_path, $headers);
    }

    private function autorizarGeneracion(Request $request, OfficialFormatGeneration $generacion): void
    {
        $usuario = $request->user();
        abort_unless($usuario->can('formatos_oficiales.descargar'), 403);

        if ($generacion->colaborador !== null) {
            abort_unless($this->alcance->puedeVerExpediente($usuario, $generacion->colaborador), 404);
        }
    }

    /**
     * @return list<array{id: int, name: string, apellidos: string|null}>
     */
    private function colaboradoresDisponibles(User $usuario): array
    {
        return array_values($this->alcance
            ->limitarColaboradoresPorAlcance(Colaborador::query()->where('estatus', 'activo'), $usuario)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'apellidos'])
            ->map(fn (Colaborador $c) => ['id' => $c->id, 'name' => $c->name, 'apellidos' => $c->apellidos])
            ->all());
    }

    /**
     * @return array<string, bool>
     */
    private function permisos(User $usuario): array
    {
        return [
            'generar' => $usuario->can('formatos_oficiales.generar'),
            'descargar' => $usuario->can('formatos_oficiales.descargar'),
            'crear' => $usuario->can('formatos_oficiales.crear'),
            'configurar' => $usuario->can('formatos_oficiales.configurar'),
            'versionar' => $usuario->can('formatos_oficiales.versionar'),
            'archivar' => $usuario->can('formatos_oficiales.archivar'),
        ];
    }
}
