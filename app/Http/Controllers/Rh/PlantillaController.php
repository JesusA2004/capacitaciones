<?php

namespace App\Http\Controllers\Rh;

use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreDocumentTemplateRequest;
use App\Http\Requests\Rh\UpdateDocumentTemplateRequest;
use App\Http\Requests\Rh\UpdateDocumentTemplateVariablesRequest;
use App\Models\DocumentTemplate;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Plantillas\PlantillaDocumentoService;
use App\Services\Plantillas\PlantillaStorageService;
use App\Services\Plantillas\VariableMappingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PlantillaController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly PlantillaStorageService $storage,
        private readonly PlantillaDocumentoService $documento,
        private readonly VariableMappingService $mapeo,
    ) {}

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Plantillas', $columnas, $filas),
            'plantillas-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Plantillas', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('plantillas-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $plantillas = $this->queryFiltrada($request)->orderBy('nombre')->get();

        $columnas = ['Nombre', 'Tipo', 'Empresa', 'Sucursal', 'Puesto', 'Versión', 'Activa', 'Fecha de creación'];

        $filas = $plantillas->map(fn (DocumentTemplate $p) => [
            $p->nombre,
            $p->tipo->etiqueta(),
            $p->empresa?->nombre,
            $p->sucursal?->nombre,
            $p->puesto?->nombre,
            $p->version,
            $p->activo ? 'Sí' : 'No',
            $p->created_at->toDateString(),
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<DocumentTemplate>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return $this->alcance
            ->limitarPorSucursal(
                DocumentTemplate::query()->with(['empresa:id,nombre', 'sucursal:id,nombre', 'puesto:id,nombre']),
                $usuario,
            )
            ->when($request->string('tipo')->toString(), fn ($query, string $tipo) => $query->where('tipo', $tipo))
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($request->integer('puesto_id'), fn ($query, $valor) => $query->where('puesto_id', $valor))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '<=', $valor))
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre', 'like', "%{$busqueda}%"));
    }

    public function store(StoreDocumentTemplateRequest $request): RedirectResponse
    {
        $archivo = $request->file('archivo');
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->rutaPlantilla($nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        DocumentTemplate::create([
            ...$request->safe()->except('archivo'),
            'disk' => config('plantillas.disk'),
            'path' => $ruta,
            'original_name' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getClientMimeType(),
            'size' => $archivo->getSize(),
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Plantilla registrada correctamente.']);
    }

    public function update(UpdateDocumentTemplateRequest $request, DocumentTemplate $plantilla): RedirectResponse
    {
        $datos = $request->safe()->except('archivo');

        if ($request->hasFile('archivo')) {
            $this->storage->eliminar($plantilla->path);

            $archivo = $request->file('archivo');
            $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
            $ruta = $this->storage->rutaPlantilla($nombreInterno);
            $this->storage->guardar($archivo, $ruta);

            $datos = [
                ...$datos,
                'path' => $ruta,
                'original_name' => $archivo->getClientOriginalName(),
                'mime' => $archivo->getClientMimeType(),
                'size' => $archivo->getSize(),
                'version' => $plantilla->version + 1,
            ];
        }

        $plantilla->update($datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Plantilla actualizada correctamente.']);
    }

    public function destroy(DocumentTemplate $plantilla): RedirectResponse
    {
        $this->authorize('delete', $plantilla);

        $plantilla->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Plantilla eliminada correctamente.']);
    }

    /**
     * Marcadores {{...}} detectados en el DOCX, cuáles ya corresponden a un
     * dato real (PlaceholderResolver), cuáles ya están mapeados como
     * variable manual, y el catálogo de referencia agrupado para copiar con
     * un clic — ver docs/DOCX_TEMPLATES.md.
     */
    public function variables(DocumentTemplate $plantilla): JsonResponse
    {
        $this->authorize('update', $plantilla);

        $detectadas = $this->documento->variablesEnPlantilla($plantilla);
        $conocidas = $this->mapeo->clavesConocidas();
        $requeridas = array_flip($this->mapeo->clavesRequeridas($plantilla));

        return response()->json([
            'detectadas' => $detectadas,
            'sin_mapear' => $this->mapeo->sinMapear($plantilla),
            'manuales' => $this->mapeo->manuales($plantilla)->all(),
            // Marcadores detectados que YA corresponden a un dato real
            // (PlaceholderResolver): por default quedan opcionales, RH
            // puede marcar cualquiera como requerido sin tocar su
            // etiqueta/tipo/valor (esos los sigue resolviendo el dato real).
            'automaticas' => collect($detectadas)
                ->filter(fn (string $clave) => in_array($clave, $conocidas, true))
                ->values()
                ->map(fn (string $clave) => [
                    'clave' => $clave,
                    'etiqueta' => $this->mapeo->etiquetar($clave),
                    'requerido' => isset($requeridas[$clave]),
                ])
                ->all(),
            'catalogo' => $this->mapeo->catalogoConocidas(),
        ]);
    }

    public function actualizarVariables(UpdateDocumentTemplateVariablesRequest $request, DocumentTemplate $plantilla): RedirectResponse
    {
        $plantilla->update(['variables_manuales' => $request->validated('variables')]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Variables de la plantilla actualizadas correctamente.']);
    }
}
