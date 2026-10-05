<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\GuardarCamposFormatoRequest;
use App\Http\Requests\Rh\NuevaVersionFormatoRequest;
use App\Http\Requests\Rh\RefinarAnalisisFormatoRequest;
use App\Http\Requests\Rh\StorePlantillaOficialRequest;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatVersion;
use App\Services\Formatos\Analisis\AnalisisPlantillaService;
use App\Services\Formatos\FormatoOficialPresenter;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Formatos\PlantillaOficialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administración de plantillas oficiales: subir, analizar, mapear campos
 * en el editor visual, versionar, publicar y archivar
 * (docs/FORMATOS_OFICIALES.md). Toda la regla vive en
 * PlantillaOficialService / AnalisisPlantillaService.
 */
class PlantillaOficialController extends Controller
{
    public function __construct(
        private readonly PlantillaOficialService $plantillas,
        private readonly AnalisisPlantillaService $analisis,
        private readonly GeneradorFormatoService $generador,
        private readonly OfficialFormatStorageService $storage,
        private readonly FormatoOficialPresenter $presenter,
    ) {}

    public function store(StorePlantillaOficialRequest $request): RedirectResponse
    {
        $descripcion = $request->validated('descripcion');
        $datos = [
            'nombre' => (string) $request->validated('nombre'),
            'tipo' => (string) $request->validated('tipo'),
            'aplica_a' => (string) $request->validated('aplica_a'),
            'empresa_id' => $request->validated('empresa_id') !== null ? (int) $request->validated('empresa_id') : null,
            'descripcion' => is_string($descripcion) ? $descripcion : null,
        ];
        $formato = $this->plantillas->crear($datos, $request->file('archivo'), $request->user());

        return redirect()
            ->route('rh.formatos-oficiales.show', $formato->id)
            ->with('toast', ['type' => 'success', 'message' => 'Plantilla subida y analizada. Revisa el mapeo de campos antes de publicarla.']);
    }

    /**
     * PDF base de una versión (fondo del editor), siempre por streaming:
     * nunca se expone la ruta del NAS.
     */
    public function base(Request $request, OfficialFormatVersion $version): StreamedResponse
    {
        $this->authorize('view', $version->formato);
        abort_if($version->base_path === null, 404);

        return $this->storage->respuesta($version->base_path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="formato-v'.$version->numero.'.pdf"',
        ]);
    }

    /**
     * Compatibilidad: PDF base de la versión vigente.
     */
    public function original(OfficialFormat $formato): StreamedResponse
    {
        $this->authorize('view', $formato);
        $version = $formato->versionVigente ?? $formato->versiones()->first();
        abort_if($version === null || $version->base_path === null, 404);

        return $this->storage->respuesta($version->base_path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $formato->slug).'.pdf"',
        ]);
    }

    public function guardarCampos(GuardarCamposFormatoRequest $request, OfficialFormatVersion $version): JsonResponse
    {
        $version = $this->plantillas->guardarCampos($version, $this->camposDe($request), $request->user());

        return response()->json(['campos' => $version->camposConfigurados(), 'message' => 'Mapeo guardado.']);
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

        return response()->json(['analisis' => $this->presenter->analisis($version->refresh())]);
    }

    /**
     * Vista previa del editor con DATOS DE EJEMPLO (rotulados) de los
     * campos enviados — sin guardarlos.
     */
    public function vistaPrevia(GuardarCamposFormatoRequest $request, OfficialFormatVersion $version): JsonResponse
    {
        $temporal = $version->replicate();
        $temporal->id = $version->id;
        $temporal->campos = $this->plantillas->normalizarCampos($version, $this->camposDe($request));

        return response()->json(['pdf_base64' => base64_encode($this->generador->vistaPreviaEjemplo($temporal))]);
    }

    public function publicar(Request $request, OfficialFormatVersion $version): RedirectResponse
    {
        $this->authorize('versionar', $version->formato);
        $this->plantillas->publicar($version, $request->user());

        return redirect()
            ->route('rh.formatos-oficiales.show', ['formato' => $version->official_format_id, 'version' => $version->id])
            ->with('toast', ['type' => 'success', 'message' => sprintf('Versión %d publicada: ya se usa para generar documentos.', $version->numero)]);
    }

    public function nuevaVersion(NuevaVersionFormatoRequest $request, OfficialFormat $formato): RedirectResponse
    {
        $version = $this->plantillas->nuevaVersion($formato, $request->file('archivo'), $request->user(), $request->validated('notas'));

        return redirect()
            ->route('rh.formatos-oficiales.show', ['formato' => $formato->id, 'version' => $version->id])
            ->with('toast', ['type' => 'success', 'message' => sprintf('Borrador v%d creado. La versión vigente sigue en uso hasta que publiques esta.', $version->numero)]);
    }

    public function descartar(Request $request, OfficialFormatVersion $version): RedirectResponse
    {
        $this->authorize('versionar', $version->formato);
        $formatoId = $version->official_format_id;
        $this->plantillas->descartarBorrador($version, $request->user());

        return redirect()
            ->route('rh.formatos-oficiales.show', $formatoId)
            ->with('toast', ['type' => 'success', 'message' => 'Borrador descartado.']);
    }

    public function archivar(Request $request, OfficialFormat $formato): RedirectResponse
    {
        $this->authorize('archivar', $formato);
        $this->plantillas->archivar($formato, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Formato archivado. Los documentos ya generados se conservan.']);
    }

    public function reactivar(Request $request, OfficialFormat $formato): RedirectResponse
    {
        $this->authorize('archivar', $formato);
        $this->plantillas->reactivar($formato, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Formato reactivado.']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function camposDe(GuardarCamposFormatoRequest $request): array
    {
        $campos = [];

        foreach ((array) $request->validated('campos', []) as $campo) {
            if (is_array($campo)) {
                $campos[] = $campo;
            }
        }

        return $campos;
    }
}
