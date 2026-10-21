<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\ResponderCorreccionRequest;
use App\Models\SolicitudInterna;
use App\Services\Expedientes\CorreccionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correcciones del expediente del colaborador autenticado (app móvil):
 * mismo CorreccionesService que «Mis solicitudes» en la web. Contrato en
 * docs/SOLICITUDES_UNIFICADAS.md («API de correcciones»).
 */
class CorreccionController extends Controller
{
    public function __construct(private readonly CorreccionesService $correcciones) {}

    /**
     * GET /api/v1/correcciones — las propias, con estado, historial,
     * comentario de RH y contador de pendientes.
     */
    public function index(Request $request): JsonResponse
    {
        $resultado = $this->correcciones->deColaborador($request->user());

        return response()->json(['data' => $resultado['items'], 'meta' => ['conteo' => $resultado['conteo']]]);
    }

    /**
     * GET /api/v1/correcciones/resumen — solo el contador (badge).
     */
    public function resumen(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->correcciones->conteo($request->user())]);
    }

    /**
     * POST /api/v1/solicitudes/{solicitud}/responder — responde lo que RH
     * pidió (requiere información) y la reenvía a revisión.
     */
    public function responder(ResponderCorreccionRequest $request, SolicitudInterna $solicitud): JsonResponse
    {
        $datos = $request->validated();
        $solicitud = $this->correcciones->responder($solicitud, $request->user(), (string) $datos['mensaje'], (array) ($datos['datos'] ?? []));

        return response()->json([
            'message' => 'Respuesta enviada. Recursos Humanos revisará de nuevo tu solicitud.',
            'data' => $this->correcciones->aArray($solicitud, $request->user()),
        ]);
    }
}
