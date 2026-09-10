<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Configuracion remota de la app, publica (sin auth:sanctum): la app la
 * consulta antes de iniciar sesion para saber si debe forzar actualizacion
 * o mostrar mantenimiento. Ver config/mobile.php y docs/API_MOVIL.md.
 */
class AppConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'maintenance' => (bool) config('mobile.maintenance'),
            'minimum_version' => config('mobile.minimum_version'),
            'latest_version' => config('mobile.latest_version'),
            'force_update' => (bool) config('mobile.force_update'),
            'message' => config('mobile.maintenance_message'),
            'features' => config('mobile.features'),
        ]);
    }
}
