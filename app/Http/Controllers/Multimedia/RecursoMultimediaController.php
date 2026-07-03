<?php

namespace App\Http\Controllers\Multimedia;

use App\Enums\EstadoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Multimedia\StoreRecursoMultimediaRequest;
use App\Jobs\ProcesarVideoJob;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecursoMultimediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $storage) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RecursoMultimedia::class);

        $recursos = RecursoMultimedia::query()
            ->with('subidoPor:id,name,apellidos')
            ->when($request->string('tipo')->toString(), fn ($query, string $tipo) => $query->where('tipo', $tipo))
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre_original', 'like', "%{$busqueda}%"))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Multimedia/Index', [
            'recursos' => $recursos,
            'filtros' => $request->only('tipo', 'busqueda'),
            'tipos' => array_map(fn (TipoRecursoMultimedia $tipo) => ['value' => $tipo->value, 'etiqueta' => $tipo->etiqueta()], TipoRecursoMultimedia::cases()),
        ]);
    }

    public function store(StoreRecursoMultimediaRequest $request): RedirectResponse
    {
        $archivo = $request->file('archivo');
        $tipo = TipoRecursoMultimedia::from($request->string('tipo')->toString());
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());

        $rutaDestino = $tipo === TipoRecursoMultimedia::Video
            ? $this->storage->rutaOriginal($nombreInterno)
            : $this->storage->rutaDocumento($nombreInterno);

        $this->storage->guardar($archivo, $rutaDestino);

        $recurso = RecursoMultimedia::create([
            'tipo' => $tipo->value,
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_interno' => $nombreInterno,
            'disco' => config('media.disk'),
            'ruta_original' => $rutaDestino,
            'mime_type' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            'estado' => $tipo === TipoRecursoMultimedia::Video ? EstadoMultimedia::Pendiente->value : EstadoMultimedia::Disponible->value,
            'subido_por' => $request->user()->id,
        ]);

        if ($tipo === TipoRecursoMultimedia::Video) {
            ProcesarVideoJob::dispatch($recurso);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Archivo cargado correctamente.']);
    }

    public function estado(RecursoMultimedia $recurso): JsonResponse
    {
        $this->authorize('view', $recurso);

        return response()->json([
            'estado' => $recurso->estado->value,
            'error_procesamiento' => $recurso->error_procesamiento,
        ]);
    }

    public function destroy(RecursoMultimedia $recurso): RedirectResponse
    {
        $this->authorize('delete', $recurso);

        $this->storage->eliminar($recurso->ruta_original);

        if ($recurso->ruta_hls_manifiesto) {
            $this->storage->eliminarCarpeta($this->storage->rutaHlsCarpeta($recurso->nombre_interno));
        }

        if ($recurso->ruta_miniatura) {
            $this->storage->eliminar($recurso->ruta_miniatura);
        }

        $recurso->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Recurso eliminado correctamente.']);
    }
}
