<?php

namespace App\Models;

use App\Enums\PlataformaApp;
use Database\Factories\MobileAppReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Version publicada de la app movil disponible para descarga directa desde
 * el sitio mientras no este en Play Store (ver
 * App\Services\AppReleases\AppReleaseStorageService y docs/APP_RELEASES.md).
 * `file_path` nunca se expone al frontend: solo se sirve por streaming a
 * traves de un controller (App\Http\Controllers\AppDownloadController /
 * App\Http\Controllers\Administracion\AppReleaseController).
 *
 * @property int $id
 * @property PlataformaApp $platform
 * @property string $version
 * @property string|null $build_number
 * @property string|null $file_path
 * @property string|null $install_url
 * @property string|null $store_url
 * @property string|null $original_filename
 * @property int|null $file_size
 * @property string|null $mime_type
 * @property string|null $sha256
 * @property string|null $changelog
 * @property bool $is_published
 * @property bool $is_latest
 * @property bool $minimum_required
 * @property int|null $uploaded_by_id
 * @property Carbon|null $published_at
 * @property-read User|null $subidoPor
 */
class MobileAppRelease extends Model
{
    /** @use HasFactory<MobileAppReleaseFactory> */
    use HasFactory;

    /**
     * Nunca se expone al frontend (ni via Inertia ni API): la descarga
     * siempre pasa por un controller que sirve el archivo en streaming. Ver
     * App\Services\AppReleases\AppReleaseStorageService.
     */
    protected $hidden = ['file_path'];

    protected $fillable = [
        'platform', 'version', 'build_number', 'file_path', 'install_url', 'store_url', 'original_filename',
        'file_size', 'mime_type', 'sha256', 'changelog', 'is_published', 'is_latest',
        'minimum_required', 'uploaded_by_id', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => PlataformaApp::class,
            'file_size' => 'integer',
            'is_published' => 'boolean',
            'is_latest' => 'boolean',
            'minimum_required' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * @param  Builder<MobileAppRelease>  $query
     * @return Builder<MobileAppRelease>
     */
    public function scopePublicadas(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function tieneArchivo(): bool
    {
        return $this->file_path !== null;
    }
}
