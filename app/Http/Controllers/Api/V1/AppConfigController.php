<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlataformaApp;
use App\Http\Controllers\Controller;
use App\Services\AppReleases\AppReleaseService;
use Illuminate\Http\JsonResponse;

/**
 * Configuracion remota de la app, publica (sin auth:sanctum): la app la
 * consulta antes de iniciar sesion para saber si debe forzar actualizacion
 * o mostrar mantenimiento. Ver config/mobile.php y docs/API_MOVIL.md.
 */
class AppConfigController extends Controller
{
    public function __construct(private readonly AppReleaseService $releases) {}

    public function __invoke(): JsonResponse
    {
        $androidLatest = $this->releases->latestPublicada(PlataformaApp::Android);
        $iosLatest = $this->releases->latestPublicada(PlataformaApp::Ios);

        return response()->json([
            'maintenance' => (bool) config('mobile.maintenance'),
            // Conservadas por compatibilidad con clientes que aun leen estas
            // claves planas (pre version-por-plataforma); no eliminar.
            'minimum_version' => config('mobile.minimum_version'),
            'latest_version' => config('mobile.latest_version'),
            'force_update' => (bool) config('mobile.force_update'),
            'message' => config('mobile.maintenance_message'),
            'minimum_android_version' => config('mobile.minimum_android_version'),
            'minimum_android_build' => config('mobile.minimum_android_build'),
            'minimum_ios_version' => config('mobile.minimum_ios_version'),
            'minimum_ios_build' => config('mobile.minimum_ios_build'),
            'features' => config('mobile.features'),
            'download_url' => $androidLatest?->tieneArchivo() ? route('app.descargar.plataforma', ['platform' => 'android']) : null,
            'update_url' => route('app.index'),
            // Distribucion iOS futura (TestFlight/App Store): null hasta que
            // exista un release publicado con esos campos. Nunca hardcodeado.
            'ios' => [
                'install_url' => $iosLatest?->install_url,
                'store_url' => $iosLatest?->store_url,
            ],
        ]);
    }
}
