<?php

namespace App\Http\Controllers;

use App\Enums\PlataformaApp;
use App\Models\MobileAppRelease;
use App\Services\AppReleases\AppReleaseService;
use App\Services\AppReleases\AppReleaseStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pagina publica de descarga de la app movil (sin auth): mientras no este en
 * Play Store, es la unica forma de que un colaborador consiga el APK. Ver
 * docs/APP_RELEASES.md. `file_path` nunca se expone; la descarga siempre
 * pasa por descargarPlataforma().
 */
class AppDownloadController extends Controller
{
    public function __construct(
        private readonly AppReleaseService $releases,
        private readonly AppReleaseStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        $latest = config('mobile_releases.download_enabled')
            ? $this->releases->latestPublicada(PlataformaApp::Android)
            : null;

        return Inertia::render('App/Index', [
            'downloadEnabled' => (bool) config('mobile_releases.download_enabled'),
            'token' => $request->string('token')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'latest' => $latest ? $this->releaseParaFrontend($latest) : null,
        ]);
    }

    public function versiones(Request $request): Response
    {
        $historial = MobileAppRelease::query()
            ->where('platform', PlataformaApp::Android->value)
            ->publicadas()
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (MobileAppRelease $r) => $this->releaseParaFrontend($r));

        return Inertia::render('App/Versiones', [
            'downloadEnabled' => (bool) config('mobile_releases.download_enabled'),
            'historial' => $historial,
        ]);
    }

    /** Atajo humano: siempre redirige a la unica plataforma soportada hoy. */
    public function descargar(Request $request): RedirectResponse
    {
        return redirect()->route('app.descargar.plataforma', array_filter([
            'platform' => 'android',
            'token' => $request->string('token')->toString() ?: null,
        ]));
    }

    public function descargarPlataforma(Request $request, string $platform): HttpResponse
    {
        abort_unless((bool) config('mobile_releases.download_enabled'), 404);

        $plataforma = PlataformaApp::tryFrom($platform);
        abort_if($plataforma === null || $plataforma !== PlataformaApp::Android, 404, 'Por ahora solo hay descarga disponible para Android.');

        $release = $this->releases->latestPublicada($plataforma);
        abort_if($release === null || ! $release->tieneArchivo(), 404, 'La descarga aún no está disponible.');

        return $this->storage->respuesta($release->file_path, [
            'Content-Type' => $release->mime_type ?? 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="mr-lana-people-'.$release->version.'.apk"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseParaFrontend(MobileAppRelease $release): array
    {
        return [
            'version' => $release->version,
            'buildNumber' => $release->build_number,
            'changelog' => $release->changelog,
            'fileSize' => $release->file_size,
            'publishedAt' => $release->published_at?->toIso8601String(),
        ];
    }
}
