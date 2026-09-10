<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreMobileAppReleaseRequest;
use App\Models\MobileAppRelease;
use App\Services\AppReleases\AppReleaseService;
use App\Services\AppReleases\AppReleaseStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Panel admin/RH para publicar versiones de la app movil (docs/APP_RELEASES.md).
 * `file_path` nunca llega al frontend: cada fila expone solo metadatos
 * (version, tamano, sha256, publicado) y la descarga pasa siempre por
 * descargar().
 */
class AppReleaseController extends Controller
{
    public function __construct(
        private readonly AppReleaseService $releases,
        private readonly AppReleaseStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('app_releases.ver'), 403);

        $releases = MobileAppRelease::query()
            ->with('subidoPor:id,name,apellidos')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Administracion/AppReleases/Index', [
            'releases' => $releases,
            'maxUploadMb' => (int) config('mobile_releases.max_upload_mb'),
            'permisos' => [
                'crear' => $request->user()->can('app_releases.crear'),
                'publicar' => $request->user()->can('app_releases.publicar'),
                'eliminar' => $request->user()->can('app_releases.eliminar'),
                'descargar' => $request->user()->can('app_releases.descargar'),
            ],
        ]);
    }

    public function store(StoreMobileAppReleaseRequest $request): RedirectResponse
    {
        $this->releases->subir($request->file('apk'), $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Versión subida. Publícala cuando esté lista para los colaboradores.']);
    }

    public function show(Request $request, MobileAppRelease $release): Response
    {
        abort_unless($request->user()->can('app_releases.ver'), 403);

        $release->loadMissing('subidoPor:id,name,apellidos');

        return Inertia::render('Administracion/AppReleases/Show', [
            'release' => $release,
            'permisos' => [
                'publicar' => $request->user()->can('app_releases.publicar'),
                'eliminar' => $request->user()->can('app_releases.eliminar'),
                'descargar' => $request->user()->can('app_releases.descargar'),
            ],
        ]);
    }

    public function publicar(Request $request, MobileAppRelease $release): RedirectResponse
    {
        abort_unless($request->user()->can('app_releases.publicar'), 403);
        abort_unless($release->tieneArchivo(), 422, 'Esta versión no tiene archivo APK.');

        $this->releases->publicar($release);

        return back()->with('toast', ['type' => 'success', 'message' => 'Versión publicada: ya es la versión más reciente disponible para descarga.']);
    }

    public function despublicar(Request $request, MobileAppRelease $release): RedirectResponse
    {
        abort_unless($request->user()->can('app_releases.publicar'), 403);

        $this->releases->despublicar($release);

        return back()->with('toast', ['type' => 'success', 'message' => 'Versión despublicada.']);
    }

    public function destroy(Request $request, MobileAppRelease $release): RedirectResponse
    {
        abort_unless($request->user()->can('app_releases.eliminar'), 403);

        $this->releases->eliminar($release);

        return back()->with('toast', ['type' => 'success', 'message' => 'Versión eliminada.']);
    }

    public function descargar(Request $request, MobileAppRelease $release): HttpResponse
    {
        abort_unless($request->user()->can('app_releases.descargar'), 403);
        abort_unless($release->tieneArchivo(), 404);

        return $this->storage->respuesta($release->file_path, [
            'Content-Type' => $release->mime_type ?? 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="mr-lana-people-'.$release->version.'.apk"',
        ]);
    }
}
