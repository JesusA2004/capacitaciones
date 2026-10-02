<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Configuracion\ConfiguracionSistemaService;
use Illuminate\Http\JsonResponse;

/**
 * Tema institucional para la app (pública, sin auth:sanctum): la app la
 * consulta al arrancar y la guarda como respaldo sin conexión, así un
 * cambio de color en Administración → Configuración → Apariencia nunca
 * obliga a publicar otra APK.
 */
class AppThemeController extends Controller
{
    public function __invoke(ConfiguracionSistemaService $configuracion): JsonResponse
    {
        $tema = $configuracion->tema();

        return response()->json([
            'data' => [
                'colors' => $tema,
                // Huella del tema: la app solo repinta si cambió.
                'version' => substr(sha1((string) json_encode($tema)), 0, 12),
            ],
        ]);
    }
}
