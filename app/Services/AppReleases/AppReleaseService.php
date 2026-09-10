<?php

namespace App\Services\AppReleases;

use App\Enums\PlataformaApp;
use App\Models\MobileAppRelease;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio del catalogo de versiones de la app movil
 * (docs/APP_RELEASES.md): subida, publicar/despublicar y borrado. El
 * almacenamiento fisico del archivo vive en
 * App\Services\AppReleases\AppReleaseStorageService; este service nunca
 * toca Storage::disk() directamente.
 */
class AppReleaseService
{
    public function __construct(private readonly AppReleaseStorageService $storage) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por StoreMobileAppReleaseRequest: version (string), build_number/changelog (string|null opcionales), minimum_required (bool opcional).
     */
    public function subir(UploadedFile $archivo, array $datos, User $subidoPor): MobileAppRelease
    {
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->rutaApk(PlataformaApp::Android->value, $nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        return MobileAppRelease::create([
            'platform' => PlataformaApp::Android->value,
            'version' => $datos['version'],
            'build_number' => $datos['build_number'] ?? null,
            'file_path' => $ruta,
            'original_filename' => $archivo->getClientOriginalName(),
            'file_size' => $archivo->getSize(),
            'mime_type' => $archivo->getMimeType() ?? 'application/vnd.android.package-archive',
            'sha256' => $this->storage->hashSha256($ruta),
            'changelog' => $datos['changelog'] ?? null,
            'minimum_required' => (bool) ($datos['minimum_required'] ?? false),
            'uploaded_by_id' => $subidoPor->id,
            'is_published' => false,
            'is_latest' => false,
        ]);
    }

    /**
     * Publica la version y la marca como "latest" de su plataforma,
     * desmarcando cualquier otra "latest" previa (dentro de una transaccion
     * para que nunca haya dos "latest" publicadas a la vez).
     */
    public function publicar(MobileAppRelease $release): void
    {
        DB::transaction(function () use ($release): void {
            MobileAppRelease::query()
                ->where('platform', $release->platform->value)
                ->where('id', '!=', $release->id)
                ->update(['is_latest' => false]);

            $release->update([
                'is_published' => true,
                'is_latest' => true,
                'published_at' => $release->published_at ?? now(),
            ]);
        });
    }

    public function despublicar(MobileAppRelease $release): void
    {
        $release->update(['is_published' => false, 'is_latest' => false]);
    }

    public function eliminar(MobileAppRelease $release): void
    {
        if ($release->file_path !== null) {
            $this->storage->eliminar($release->file_path);
        }

        $release->delete();
    }

    public function latestPublicada(PlataformaApp $platform): ?MobileAppRelease
    {
        return MobileAppRelease::query()
            ->where('platform', $platform->value)
            ->publicadas()
            ->where('is_latest', true)
            ->first();
    }
}
