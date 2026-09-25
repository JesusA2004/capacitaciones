<?php

namespace App\Models;

use App\Enums\AplicaFormato;
use App\Enums\EstadoVersionFormato;
use App\Enums\TipoFormatoOficial;
use Database\Factories\OfficialFormatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plantilla oficial de MR. LANA (docs/FORMATOS_OFICIALES.md): el FORMATO
 * (nombre, categoría, a quién aplica, empresa). El archivo, el mapeo de
 * campos y el análisis viven en sus versiones (OfficialFormatVersion); solo
 * la versión vigente (publicada) genera documentos nuevos.
 *
 * `source_*` y `overlay_config` son columnas legacy (antes del versionado):
 * se conservan con su información original pero ya no se leen — la versión
 * 1 de cada formato se creó a partir de ellas (migración
 * 2026_09_25_120000_versionar_formatos_oficiales).
 *
 * Nunca se borra un formato que ya generó documentos: se archiva
 * (archivado_en / is_active = false).
 *
 * @property int $id
 * @property string $slug
 * @property string $nombre
 * @property string|null $descripcion
 * @property TipoFormatoOficial $tipo
 * @property AplicaFormato $aplica_a
 * @property int|null $empresa_id
 * @property int|null $version_vigente_id
 * @property string|null $source_disk
 * @property string|null $source_path
 * @property string|null $original_filename
 * @property string $file_type
 * @property bool $is_active
 * @property array<string, array<string, mixed>>|null $overlay_config
 * @property int|null $created_by
 * @property Carbon|null $archivado_en
 * @property int|null $archivado_por
 * @property-read OfficialFormatVersion|null $versionVigente
 * @property-read Empresa|null $empresa
 * @property-read int $generaciones_count
 */
class OfficialFormat extends Model
{
    /** @use HasFactory<OfficialFormatFactory> */
    use HasFactory;

    protected $hidden = ['source_disk', 'source_path', 'overlay_config'];

    protected $fillable = [
        'slug',
        'nombre',
        'descripcion',
        'tipo',
        'aplica_a',
        'empresa_id',
        'version_vigente_id',
        'source_disk',
        'source_path',
        'original_filename',
        'file_type',
        'is_active',
        'overlay_config',
        'created_by',
        'archivado_en',
        'archivado_por',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'aplica_a' => 'colaborador',
        'is_active' => true,
        'file_type' => 'pdf',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoFormatoOficial::class,
            'aplica_a' => AplicaFormato::class,
            'is_active' => 'boolean',
            'overlay_config' => 'array',
            'archivado_en' => 'datetime',
        ];
    }

    /**
     * "Listo para generar": activo y con una versión vigente publicada que
     * tenga al menos un campo mapeado.
     */
    public function tieneConfiguracion(): bool
    {
        $version = $this->versionVigente;

        return $this->is_active
            && $version !== null
            && $version->estado === EstadoVersionFormato::Publicada
            && $version->camposConfigurados() !== [];
    }

    public function estaArchivado(): bool
    {
        return $this->archivado_en !== null;
    }

    /**
     * @return HasMany<OfficialFormatVersion, $this>
     */
    public function versiones(): HasMany
    {
        return $this->hasMany(OfficialFormatVersion::class, 'official_format_id')->orderByDesc('numero');
    }

    /**
     * @return BelongsTo<OfficialFormatVersion, $this>
     */
    public function versionVigente(): BelongsTo
    {
        return $this->belongsTo(OfficialFormatVersion::class, 'version_vigente_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<OfficialFormatGeneration, $this>
     */
    public function generaciones(): HasMany
    {
        return $this->hasMany(OfficialFormatGeneration::class);
    }
}
