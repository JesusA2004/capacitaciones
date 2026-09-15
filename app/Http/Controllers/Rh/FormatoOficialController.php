<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\GenerarFormatoOficialRequest;
use App\Http\Requests\Rh\GuardarConfiguracionFormatoOficialRequest;
use App\Models\Candidato;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Formatos\OfficialFormatCatalogoService;
use App\Services\Formatos\OfficialFormatOverlayService;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Solicitudes\SolicitudFormatoOficialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Formatos oficiales fijos de MR. LANA (docs/FORMATOS_OFICIALES.md): a
 * diferencia de Rh\FormatoController (plantillas DOCX editables), aquí RH
 * nunca sube ni cambia el documento — solo selecciona colaborador, revisa
 * los datos detectados y genera un PDF con overlay sobre el original.
 */
class FormatoOficialController extends Controller
{
    public function __construct(
        private readonly OfficialFormatCatalogoService $catalogo,
        private readonly OfficialFormatOverlayService $overlay,
        private readonly OfficialFormatStorageService $storage,
        private readonly PlaceholderResolver $resolver,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudFormatoOficialService $formatoDeSolicitud,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OfficialFormat::class);

        $usuario = $request->user();

        return Inertia::render('Rh/FormatosOficiales/Index', [
            'formatos' => $this->catalogo->listar(),
            'colaboradoresDisponibles' => $this->alcance
                ->limitarUsuariosPorAlcance(User::query(), $usuario)
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'apellidos']),
            'candidatosDisponibles' => Candidato::query()->orderBy('nombre')->limit(200)->get(['id', 'nombre', 'apellidos']),
            'permisos' => [
                'generar' => $usuario->can('formatos_oficiales.generar'),
                'descargar' => $usuario->can('formatos_oficiales.descargar'),
                'configurar' => $usuario->can('formatos_oficiales.configurar'),
            ],
        ]);
    }

    public function show(OfficialFormat $formato): Response
    {
        $this->authorize('configurar', $formato);

        return Inertia::render('Rh/FormatosOficiales/Configurar', [
            'formato' => [
                'id' => $formato->id,
                'slug' => $formato->slug,
                'nombre' => $formato->nombre,
                'tipo_etiqueta' => $formato->tipo->etiqueta(),
                'file_type' => $formato->file_type,
                'overlay_config' => $formato->overlay_config ?? [],
            ],
            'camposDisponibles' => collect(OfficialFormatOverlayService::CAMPOS_DISPONIBLES)
                ->map(fn (string $etiqueta, string $clave) => ['clave' => $clave, 'etiqueta' => $etiqueta])
                ->values(),
        ]);
    }

    public function original(OfficialFormat $formato): StreamedResponse
    {
        $this->authorize('view', $formato);

        return $this->storage->respuesta($formato->source_path, [
            'Content-Disposition' => 'inline; filename="'.$formato->original_filename.'"',
        ]);
    }

    public function guardarConfiguracion(GuardarConfiguracionFormatoOficialRequest $request, OfficialFormat $formato): RedirectResponse
    {
        $formato->update(['overlay_config' => $request->validated('overlay_config')]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Configuración guardada.']);
    }

    public function previsualizarConfiguracion(GuardarConfiguracionFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        abort_unless($formato->file_type === 'pdf', 422, 'La vista previa por overlay solo está disponible para formatos PDF.');

        $formatoTemporal = clone $formato;
        $formatoTemporal->overlay_config = $request->validated('overlay_config');

        $pdf = $this->overlay->generar($formatoTemporal, OfficialFormatOverlayService::datosMuestra());

        return response()->json(['pdf_base64' => base64_encode($pdf)]);
    }

    public function previsualizarGeneracion(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        abort_unless($formato->file_type === 'pdf', 422, 'La generación por overlay solo está disponible para formatos PDF.');

        $sujeto = $this->resolverSujeto((string) $request->validated('tipo_sujeto'), (int) $request->validated('sujeto_id'));
        abort_unless($sujeto !== null, 404, 'No se encontró el colaborador o candidato indicado.');
        $this->autorizarSujeto($request->user(), $sujeto);

        if (! $formato->tieneConfiguracion()) {
            return response()->json([
                'message' => 'Este formato necesita configurar dónde se colocarán los datos.',
            ], 422);
        }

        $datos = $this->resolver->resolver($sujeto, $request->validated('extra') ?? []);
        $pdf = $this->overlay->generar($formato, $datos);

        return response()->json([
            'pdf_base64' => base64_encode($pdf),
            'datos' => $this->datosVisibles($formato, $datos),
            'faltantes' => $this->calcularFaltantes($formato, $datos),
        ]);
    }

    public function generar(GenerarFormatoOficialRequest $request, OfficialFormat $formato): JsonResponse
    {
        abort_unless($formato->file_type === 'pdf', 422, 'La generación por overlay solo está disponible para formatos PDF.');

        $sujeto = $this->resolverSujeto((string) $request->validated('tipo_sujeto'), (int) $request->validated('sujeto_id'));
        abort_unless($sujeto !== null, 404, 'No se encontró el colaborador o candidato indicado.');
        $this->autorizarSujeto($request->user(), $sujeto);

        abort_unless($formato->tieneConfiguracion(), 422, 'Este formato necesita configurar dónde se colocarán los datos.');

        $datos = $this->resolver->resolver($sujeto, $request->validated('extra') ?? []);
        $pdf = $this->overlay->generar($formato, $datos);

        $ruta = $this->storage->rutaGenerado();
        $this->storage->guardarContenido($ruta, $pdf);

        $generacion = OfficialFormatGeneration::create([
            'official_format_id' => $formato->id,
            'user_id' => $sujeto instanceof User ? $sujeto->id : null,
            'candidato_id' => $sujeto instanceof Candidato ? $sujeto->id : null,
            'generated_by_id' => $request->user()->id,
            'generated_disk' => config('formatos_oficiales.disk'),
            'generated_path' => $ruta,
            'generated_name' => str($formato->nombre)->slug().'-'.now()->format('Y-m-d-His').'.pdf',
            'data_snapshot' => $datos,
        ]);

        return response()->json([
            'generacion' => [
                'id' => $generacion->id,
                'nombre' => $generacion->generated_name,
                'descargar_url' => route('rh.formatos-oficiales.descargar', $generacion->id),
            ],
        ]);
    }

    public function descargar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        $this->autorizarGeneracion($request, $generacion);

        return $this->storage->respuesta($generacion->generated_path, [
            'Content-Disposition' => 'attachment; filename="'.$generacion->generated_name.'"',
        ]);
    }

    /**
     * Igual que descargar(), pero inline: para que
     * resources/js/components/people/DocumentPreviewDialog.vue lo pueda
     * embeber sin forzar la descarga (sección 61 del encargo).
     */
    public function previsualizar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        $this->autorizarGeneracion($request, $generacion);

        return $this->storage->respuesta($generacion->generated_path, [
            'Content-Disposition' => 'inline; filename="'.$generacion->generated_name.'"',
        ]);
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

    private function autorizarGeneracion(Request $request, OfficialFormatGeneration $generacion): void
    {
        $usuario = $request->user();
        abort_unless($usuario->can('formatos_oficiales.descargar'), 403);

        if ($generacion->usuario !== null) {
            abort_unless($this->alcance->puedeVerUsuario($usuario, $generacion->usuario), 404);
        }
    }

    private function resolverSujeto(string $tipoSujeto, int $sujetoId): User|Candidato|null
    {
        return $tipoSujeto === 'colaborador'
            ? User::query()->firstWhere('id', $sujetoId)
            : Candidato::query()->firstWhere('id', $sujetoId);
    }

    private function autorizarSujeto(User $usuario, User|Candidato $sujeto): void
    {
        if ($sujeto instanceof User) {
            abort_unless($this->alcance->puedeVerUsuario($usuario, $sujeto), 404);
        }
    }

    /**
     * @param  array<string, string>  $datos
     * @return array<int, string>
     */
    private function calcularFaltantes(OfficialFormat $formato, array $datos): array
    {
        return collect($formato->overlay_config ?? [])
            ->filter(fn (array $campo) => ($campo['enabled'] ?? false) === true)
            ->keys()
            ->filter(fn (string $clave) => trim((string) ($datos[$clave] ?? '')) === '')
            ->values()
            ->all();
    }

    /**
     * Solo los campos realmente configurados en el overlay, para no exponer
     * al frontend datos que el formato ni siquiera usa.
     *
     * @param  array<string, string>  $datos
     * @return array<string, string>
     */
    private function datosVisibles(OfficialFormat $formato, array $datos): array
    {
        return array_intersect_key($datos, $formato->overlay_config ?? []);
    }
}
