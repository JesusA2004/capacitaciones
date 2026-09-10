<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlataformaApp;
use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Services\AppReleases\AppReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Version(es) publicadas de la app movil, consumidas por la propia app y por
 * la pagina publica /app (docs/APP_RELEASES.md). Publico, sin auth:sanctum —
 * la app lo consulta para saber si hay actualizacion antes de iniciar
 * sesion, igual que /api/v1/app/config.
 */
class AppReleaseController extends Controller
{
    public function __construct(private readonly AppReleaseService $releases) {}

    public function latest(Request $request): JsonResponse
    {
        $plataforma = PlataformaApp::tryFrom($request->string('platform')->toString() ?: 'android') ?? PlataformaApp::Android;

        $release = $this->releases->latestPublicada($plataforma);

        if ($release === null) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => $this->paraApi($release)]);
    }

    public function index(Request $request): JsonResponse
    {
        $plataforma = PlataformaApp::tryFrom($request->string('platform')->toString() ?: 'android') ?? PlataformaApp::Android;

        $releases = MobileAppRelease::query()
            ->where('platform', $plataforma->value)
            ->publicadas()
            ->orderByDesc('published_at')
            ->get();

        return response()->json([
            'data' => $releases->map(fn (MobileAppRelease $r) => $this->paraApi($r))->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paraApi(MobileAppRelease $release): array
    {
        return [
            'platform' => $release->platform->value,
            'version' => $release->version,
            'build_number' => $release->build_number,
            // Android se descarga como APK propio; iOS (sin archivo todavia)
            // solo expone install_url/store_url cuando existan — nunca
            // hardcodeado en la app.
            'download_url' => $release->tieneArchivo()
                ? route('app.descargar.plataforma', ['platform' => $release->platform->value])
                : null,
            'install_url' => $release->install_url,
            'store_url' => $release->store_url,
            'file_size' => $release->file_size,
            'sha256' => $release->sha256,
            'changelog' => $release->changelog,
            'minimum_required' => $release->minimum_required,
            'published_at' => $release->published_at?->toIso8601String(),
        ];
    }
}
