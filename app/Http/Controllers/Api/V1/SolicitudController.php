<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudAutoservicioRequest;
use App\Http\Resources\Api\V1\SolicitudInternaResource;
use App\Models\SolicitudInterna;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Solicitudes internas propias del colaborador autenticado. Un colaborador
 * jamás ve solicitudes de otros a través de esta API (Fase 1 — la revisión
 * de RH/gerencia queda solo en la web, ver docs/API_MOVIL.md).
 */
class SolicitudController extends Controller
{
    public function __construct(private readonly SolicitudesService $solicitudes) {}

    public function index(Request $request): JsonResponse
    {
        $solicitudes = $this->solicitudes->paraColaborador($request->user());

        return response()->json([
            'data' => SolicitudInternaResource::collection($solicitudes->items()),
            'meta' => [
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
                'total' => $solicitudes->total(),
            ],
        ]);
    }

    public function store(StoreSolicitudAutoservicioRequest $request): JsonResponse
    {
        $solicitud = $this->solicitudes->crear($request->user(), $request->validated());

        return response()->json(new SolicitudInternaResource($solicitud), 201);
    }

    public function show(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['documentos', 'historial']);

        return response()->json(new SolicitudInternaResource($solicitud));
    }

    /**
     * Cancela una solicitud propia (mismo criterio que la web, ver
     * Solicitudes\SolicitudInternaController::cancelar): solo mientras
     * sigue en manos propias o apenas entrando a revisión, nunca una ya
     * aprobada/rechazada/cerrada.
     */
    public function cancelar(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $this->authorize('cancelar', $solicitud);

        $solicitud = $this->solicitudes->cancelar($solicitud, $request->user());

        return response()->json(['message' => 'Solicitud cancelada.', 'data' => new SolicitudInternaResource($solicitud)]);
    }

    /**
     * Catalogo de tipos de solicitud + reglas de formulario, para que la app
     * construya la pantalla de "nueva solicitud" sin hardcodear nada. Ver
     * seccion 14 del encargo movil.
     */
    public function configuracion(): JsonResponse
    {
        return response()->json(['tipos' => $this->solicitudes->tiposConFormulario(autoservicio: true)]);
    }

    /**
     * Adjunta un archivo a una solicitud propia. Nunca a la de otro
     * colaborador (403), ni PDF/JPG/PNG fuera del limite configurado.
     */
    public function adjuntos(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        abort_unless($solicitud->user_id === $request->user()->id, 403);

        $datos = $request->validate([
            'archivo' => [
                'required',
                'file',
                'max:'.(config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('expedientes.extensiones_permitidas')),
            ],
        ]);

        $this->solicitudes->adjuntarDocumento($solicitud, $datos['archivo'], $request->user());

        return response()->json(['message' => 'Adjunto agregado correctamente.'], 201);
    }
}
