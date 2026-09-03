<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ComentarioSolicitudInternaRequest;
use App\Http\Requests\Rh\RechazarSolicitudInternaRequest;
use App\Models\Empresa;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SolicitudController extends Controller
{
    public function __construct(private readonly SolicitudesService $solicitudes) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SolicitudInterna::class);

        return Inertia::render('Rh/Solicitudes/Index', [
            'solicitudes' => $this->solicitudes->paraRevision($request->user(), $request->only(['estado', 'tipo', 'sucursal_id', 'empresa_id'])),
            'filtros' => $request->only(['estado', 'tipo', 'sucursal_id', 'empresa_id']),
            'tipos' => $this->solicitudes->tiposDisponibles(),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            ],
        ]);
    }

    public function show(SolicitudInterna $solicitud): Response
    {
        $this->authorize('view', $solicitud);

        $solicitud->load([
            'usuario:id,name,apellidos,puesto_id,sucursal_principal_id',
            'usuario.puesto:id,nombre',
            'usuario.sucursalPrincipal:id,nombre',
            'revisadoPor:id,name,apellidos',
            'documentos.subidoPor:id,name,apellidos',
            'historial.usuario:id,name,apellidos',
        ]);

        return Inertia::render('Rh/Solicitudes/Show', [
            'solicitud' => $solicitud,
        ]);
    }

    public function revisar(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('revisar', $solicitud);

        $this->solicitudes->marcarEnRevision($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud marcada en revisión.']);
    }

    public function requerirCorreccion(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('revisar', $solicitud);

        $this->solicitudes->requerirCorreccion($solicitud, $request->user(), (string) $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Se pidió corrección al colaborador.']);
    }

    public function aprobar(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('aprobar', $solicitud);

        $this->solicitudes->aprobar($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud aprobada.']);
    }

    public function rechazar(RechazarSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('rechazar', $solicitud);

        $this->solicitudes->rechazar($solicitud, $request->user(), $request->validated('motivo_rechazo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud rechazada.']);
    }

    public function cerrar(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('cerrar', $solicitud);

        $this->solicitudes->cerrar($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud cerrada.']);
    }
}
