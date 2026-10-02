<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoSolicitudInterna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vacaciones\StoreSolicitudVacacionesRequest;
use App\Http\Resources\Api\V1\SolicitudVacacionesResource;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints LEGACY de vacaciones (`/api/v1/vacaciones/*`) que todavía
 * llaman versiones viejas de la app. La fuente de verdad es el motor
 * unificado de Solicitudes (visto bueno gerente → regional → autorización
 * RH): una solicitud nueva por aquí se crea ALLÁ (SolicitudInterna tipo
 * vacaciones), nunca en la tabla legacy, para que ningún flujo permita a un
 * gerente aprobarla en definitiva.
 */
class VacacionesController extends Controller
{
    public function __construct(
        private readonly VacacionesService $vacaciones,
        private readonly SolicitudesService $solicitudes,
    ) {}

    public function saldo(Request $request): JsonResponse
    {
        return response()->json($this->vacaciones->saldo($request->user()));
    }

    public function solicitudes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SolicitudVacacionesResource::collection($this->vacaciones->misSolicitudes($request->user())),
        ]);
    }

    public function storeSolicitud(StoreSolicitudVacacionesRequest $request): JsonResponse
    {
        $datos = $request->validated();

        try {
            $solicitud = $this->solicitudes->crear($request->user(), [
                'tipo' => TipoSolicitudInterna::Vacaciones->value,
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
                'dias_solicitados' => (int) $datos['dias_solicitados'],
                'motivo' => isset($datos['comentario']) && trim((string) $datos['comentario']) !== '' ? (string) $datos['comentario'] : 'Solicitud de vacaciones',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'No tienes suficientes días disponibles.', 'errors' => $e->errors()], 422);
        }

        // Misma forma que respondía el endpoint legacy (apps viejas), más el
        // id de la solicitud unificada para que una app nueva la abra.
        return response()->json([
            'id' => $solicitud->id,
            'solicitud_id' => $solicitud->id,
            'fecha_inicio' => $solicitud->fecha_inicio?->toDateString(),
            'fecha_fin' => $solicitud->fecha_fin?->toDateString(),
            'dias_solicitados' => $solicitud->dias_solicitados,
            'comentario' => $datos['comentario'] ?? null,
            'estado' => 'pendiente',
            'estado_etiqueta' => $solicitud->estado->etiqueta(),
            'motivo_rechazo' => null,
            'creada_en' => $solicitud->created_at?->toIso8601String(),
        ], 201);
    }
}
