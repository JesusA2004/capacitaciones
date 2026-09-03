<?php

namespace App\Http\Controllers\Rh;

use App\Enums\TipoPlantillaDocumento;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreDocumentTemplateRequest;
use App\Http\Requests\Rh\UpdateDocumentTemplateRequest;
use App\Models\DocumentTemplate;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Plantillas\PlantillaStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PlantillaController extends Controller
{
    private const FILTROS = ['tipo', 'empresa_id', 'sucursal_id', 'puesto_id', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly PlantillaStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $plantillas = $this->queryFiltrada($request)->orderBy('nombre')->get();

        return Inertia::render('Rh/Plantillas/Index', [
            'plantillas' => $plantillas,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
                'tipos' => array_map(fn (TipoPlantillaDocumento $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoPlantillaDocumento::cases()),
            ],
        ]);
    }

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
}
