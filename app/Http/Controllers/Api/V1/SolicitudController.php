<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoSolicitudInterna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudAutoservicioRequest;
use App\Http\Resources\Api\V1\SolicitudInternaResource;
use App\Models\SolicitudInterna;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Solicitudes internas propias del colaborador autenticado. Un colaborador
 * jamás ve solicitudes de otros a través de esta API (Fase 1 — la revisión
 * de RH/gerencia queda solo en la web, ver docs/API_MOVIL.md).
 */
class SolicitudController extends Controller
{
    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly AprobacionOrganizacionalService $motor,
    ) {}

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

    /**
     * Formato oficial de permiso (solo cuando RH ya lo autorizó).
     */
    public function permisoPdf(SolicitudInterna $solicitud): StreamedResponse
    {
        $this->authorize('view', $solicitud);

        try {
            return $this->solicitudes->respuestaPermiso($solicitud);
        } catch (ValidationException $e) {
            // No es falta de permiso: el formato todavía no se libera (RH no
            // ha autorizado). 409 con el motivo real, no un 403 engañoso.
            abort(409, (string) collect($e->errors())->flatten()->first());
        }
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
     * aprobada/rechazada.
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
    public function configuracion(Request $request): JsonResponse
    {
        $elegibilidad = $this->motor->elegibilidadPrestamo($request->user()->colaborador);

        // El tipo «prestamo» lleva su elegibilidad (6 meses completos,
        // fecha del servidor) para que la app deshabilite el botón con el
        // texto y la fecha exacta; el backend vuelve a validar al crear.
        $tipos = array_map(
            fn (array $tipo): array => $tipo['clave'] === TipoSolicitudInterna::PrestamoInterno->value
                ? [...$tipo, 'disponible' => $elegibilidad['cumple'], 'elegibilidad' => $elegibilidad]
                : $tipo,
            $this->solicitudes->tiposConFormulario(autoservicio: true),
        );

        return response()->json(['tipos' => $tipos, 'elegibilidad_prestamo' => $elegibilidad]);
    }

    /**
     * Elegibilidad de préstamo del colaborador autenticado: «Antigüedad
     * actual: 5 meses 12 días», «Disponible desde: 08/11/2026».
     */
    public function elegibilidadPrestamo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->motor->elegibilidadPrestamo($request->user()->colaborador)]);
    }

    /**
     * Adjunta un archivo a una solicitud que el usuario puede ver (la propia,
     * o una de su alcance si es RH) — mismo criterio que la web. Rechaza
     * archivos fuera del límite/extensiones configurados.
     */
    public function adjuntos(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        // Mismo gate que la web (SubirDocumentoSolicitudRequest usa can('view')):
        // el dueño adjunta evidencia a la suya y RH a las de su alcance, sin
        // dos reglas distintas por canal (P2-15). SolicitudInternaPolicy::view
        // ya resuelve «propia» vs. alcance de módulo.
        $this->authorize('view', $solicitud);

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
