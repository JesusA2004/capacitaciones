<?php

namespace App\Http\Controllers\Actividades;

use App\Enums\EstadoEntregaActividad;
use App\Http\Controllers\Controller;
use App\Http\Requests\Actividades\CalificarEntregaActividadRequest;
use App\Models\EntregaActividad;
use App\Services\Evaluacion\EntregaActividadService;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalificacionActividadController extends Controller
{
    public function __construct(
        private readonly EntregaActividadService $service,
        private readonly MediaStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('respuestas.ver');

        $entregas = EntregaActividad::query()
            ->where('estado', EstadoEntregaActividad::Entregada->value)
            ->with(['usuario:id,name,apellidos', 'actividad:id,titulo,leccion_id'])
            ->orderBy('entregado_en')
            ->paginate(15);

        return Inertia::render('Actividades/Calificaciones/Index', [
            'entregas' => $entregas,
        ]);
    }

    public function show(EntregaActividad $entrega): Response
    {
        $this->authorize('respuestas.ver');

        $entrega->load(['usuario:id,name,apellidos', 'actividad', 'recursoMultimedia:id,nombre_original']);

        return Inertia::render('Actividades/Calificaciones/Show', [
            'entrega' => $entrega,
        ]);
    }

    public function descargar(EntregaActividad $entrega): StreamedResponse
    {
        $this->authorize('respuestas.ver');

        $entrega->loadMissing('recursoMultimedia');
        abort_unless($entrega->recursoMultimedia !== null, 404);

        return $this->storage->respuesta($entrega->recursoMultimedia->ruta_original, [
            'Content-Disposition' => 'attachment; filename="'.$entrega->recursoMultimedia->nombre_original.'"',
        ]);
    }

    public function calificar(CalificarEntregaActividadRequest $request, EntregaActividad $entrega): RedirectResponse
    {
        $this->service->calificar(
            $entrega,
            $request->boolean('aprobada'),
            $request->input('calificacion'),
            $request->input('retroalimentacion'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Entrega calificada correctamente.']);
    }
}
