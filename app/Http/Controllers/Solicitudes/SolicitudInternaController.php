<?php

namespace App\Http\Controllers\Solicitudes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudInternaRequest;
use App\Http\Requests\Solicitudes\SubirDocumentoSolicitudRequest;
use App\Models\SolicitudInterna;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vista del colaborador sobre sus propias solicitudes. La revisión de RH/
 * gerencia vive en App\Http\Controllers\Rh\SolicitudController — mismo
 * SolicitudesService, controladores separados (ver sección 4 del encargo).
 */
class SolicitudInternaController extends Controller
{
    public function __construct(private readonly SolicitudesService $solicitudes) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Solicitudes/Index', [
            'solicitudes' => $this->solicitudes->paraColaborador($request->user()),
            'tipos' => $this->solicitudes->tiposDisponibles(),
        ]);
    }

    public function store(StoreSolicitudInternaRequest $request): RedirectResponse
    {
        $this->solicitudes->crear($request->user(), $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud enviada. Te avisaremos cuando sea revisada.']);
    }

    public function show(Request $request, SolicitudInterna $solicitud): Response
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['usuario:id,name,apellidos', 'revisadoPor:id,name,apellidos', 'documentos', 'documentosGenerados.plantilla:id,nombre,tipo', 'historial.usuario:id,name,apellidos']);

        return Inertia::render('Solicitudes/Show', [
            'solicitud' => $solicitud,
        ]);
    }

    public function cancelar(SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('cancelar', $solicitud);

        $this->solicitudes->cancelar($solicitud, request()->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud cancelada.']);
    }

    public function subirDocumento(SubirDocumentoSolicitudRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->solicitudes->adjuntarDocumento($solicitud, $request->file('archivo'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento adjuntado.']);
    }
}
