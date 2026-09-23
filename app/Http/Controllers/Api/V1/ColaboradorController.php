<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudAutoservicioRequest;
use App\Http\Resources\Api\V1\SolicitudInternaResource;
use App\Services\Colaboradores\ColaboradorPerfilService;
use App\Services\Colaboradores\NotificacionesService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Datos propios del colaborador para la app móvil. Toda la lógica vive en
 * ColaboradorPerfilService/SolicitudesService/NotificacionesService/
 * VacacionesService — los mismos servicios que usan las páginas Inertia —
 * este controlador solo autoriza (implícito: siempre "el usuario
 * autenticado"), valida y transforma a JSON.
 */
class ColaboradorController extends Controller
{
    public function __construct(
        private readonly ColaboradorPerfilService $perfil,
        private readonly SolicitudesService $solicitudes,
        private readonly NotificacionesService $notificaciones,
        private readonly VacacionesService $vacaciones,
        private readonly DocumentoStorageService $storage,
    ) {}

    public function perfil(Request $request): JsonResponse
    {
        $datos = $this->perfil->perfil($request->user());
        // La API movil usa Bearer token (sin sesion web): la foto se sirve
        // por una ruta propia autenticada con Sanctum, nunca la ruta web
        // protegida por sesion que usa el resto del portal. Ver foto().
        // foto_path vive en Colaborador (users.foto_path es una columna
        // legacy que nunca se escribe, ver Expedientes\ExpedienteNasOrganizacionService).
        $datos['foto_url'] = $request->user()->colaborador?->foto_path !== null ? route('api.v1.colaborador.foto') : null;

        return response()->json($datos);
    }

    /**
     * Sirve la foto de perfil del propio colaborador autenticado en
     * streaming: nunca expone `foto_path` (ruta fisica en el disco NAS) al
     * cliente, solo el binario. Ver seccion 13 del encargo movil.
     */
    public function foto(Request $request): StreamedResponse
    {
        $colaborador = $request->user()->colaborador;
        abort_unless($colaborador?->foto_path !== null, 404);

        return $this->storage->respuesta($colaborador->foto_path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $datos = $this->perfil->dashboard($request->user());
        $datos['perfil']['foto_url'] = $request->user()->colaborador?->foto_path !== null ? route('api.v1.colaborador.foto') : null;

        return response()->json($datos);
    }

    public function vacaciones(Request $request): JsonResponse
    {
        return response()->json($this->vacaciones->saldo($request->user()));
    }

    public function solicitudes(Request $request): JsonResponse
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

    public function storeSolicitud(StoreSolicitudAutoservicioRequest $request): JsonResponse
    {
        $solicitud = $this->solicitudes->crear($request->user(), $request->validated());

        return response()->json(new SolicitudInternaResource($solicitud), 201);
    }

    public function notificaciones(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->notificaciones->listar($request->user()),
        ]);
    }
}
