<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoDocumentoGenerado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreGeneratedDocumentRequest;
use App\Models\Candidato;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Plantillas\PlantillaDocumentoService;
use App\Services\Plantillas\PlantillaStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FormatoController extends Controller
{
    public function __construct(
        private readonly PlantillaDocumentoService $generador,
        private readonly PlantillaStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $documentos = GeneratedDocument::query()
            ->with(['plantilla:id,nombre,tipo', 'usuario:id,name,apellidos', 'candidato:id,nombre,apellidos', 'generadoPor:id,name,apellidos'])
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Rh/Formatos/Index', [
            'documentos' => $documentos,
            'plantillasDisponibles' => DocumentTemplate::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo']),
            'colaboradoresDisponibles' => User::query()->orderBy('name')->limit(200)->get(['id', 'name', 'apellidos']),
            'candidatosDisponibles' => Candidato::query()->orderBy('nombre')->limit(200)->get(['id', 'nombre', 'apellidos']),
        ]);
    }

    public function store(StoreGeneratedDocumentRequest $request): RedirectResponse
    {
        $plantilla = DocumentTemplate::query()->where('id', $request->validated('document_template_id'))->firstOrFail();
        $this->authorize('generar', $plantilla);

        $sujeto = $request->validated('tipo_sujeto') === 'colaborador'
            ? User::query()->firstWhere('id', $request->validated('sujeto_id'))
            : Candidato::query()->firstWhere('id', $request->validated('sujeto_id'));

        abort_unless($sujeto !== null, 404, 'No se encontró el colaborador o candidato indicado.');

        $resultado = $this->generador->generar($plantilla, $sujeto, $request->validated('extra') ?? []);
        $ruta = $this->storage->rutaGenerado($resultado['nombre_interno']);
        $this->storage->guardarContenido($ruta, $resultado['contenido']);

        $nombreGenerado = $plantilla->tipo->etiqueta().' - '.now()->format('Y-m-d').'.docx';

        GeneratedDocument::create([
            'document_template_id' => $plantilla->id,
            'user_id' => $sujeto instanceof User ? $sujeto->id : null,
            'candidato_id' => $sujeto instanceof Candidato ? $sujeto->id : null,
            'empresa_id' => $plantilla->empresa_id,
            'sucursal_id' => $plantilla->sucursal_id,
            'disk' => config('plantillas.disk'),
            'path' => $ruta,
            'original_name' => $plantilla->original_name,
            'generated_name' => $nombreGenerado,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => strlen($resultado['contenido']),
            'status' => EstadoDocumentoGenerado::Generado,
            'generated_by' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento generado correctamente.']);
    }

    public function descargar(GeneratedDocument $documento): StreamedResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        if ($documento->status === EstadoDocumentoGenerado::Generado) {
            $documento->update(['status' => EstadoDocumentoGenerado::Entregado]);
        }

        return $this->storage->respuesta($documento->path, [
            'Content-Disposition' => 'attachment; filename="'.$documento->generated_name.'"',
        ]);
    }

    public function destroy(GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $this->storage->eliminar($documento->path);
        $documento->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento generado eliminado.']);
    }
}
