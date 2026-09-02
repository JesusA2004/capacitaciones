<?php

namespace App\Http\Controllers\Rh;

use App\Enums\TipoPlantillaDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreDocumentTemplateRequest;
use App\Http\Requests\Rh\UpdateDocumentTemplateRequest;
use App\Models\DocumentTemplate;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Plantillas\PlantillaStorageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlantillaController extends Controller
{
    public function __construct(private readonly PlantillaStorageService $storage) {}

    public function index(): Response
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $plantillas = DocumentTemplate::query()
            ->with(['empresa:id,nombre', 'sucursal:id,nombre', 'puesto:id,nombre'])
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Rh/Plantillas/Index', [
            'plantillas' => $plantillas,
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
                'tipos' => array_map(fn (TipoPlantillaDocumento $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoPlantillaDocumento::cases()),
            ],
        ]);
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
