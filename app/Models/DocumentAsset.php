<?php

namespace App\Models;

use App\Enums\AjusteFondo;
use App\Enums\TipoDocumentAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recurso gráfico administrado de los documentos maestros (fondo de página,
 * logo, sello, marca de agua). El archivo nunca se sobrescribe: reemplazar
 * crea otra fila (mismo slug, version+1, `reemplaza_a_id`). Se identifica
 * por SHA-256, no por nombre. Ver LayoutDocumentoService.
 *
 * @property int $id
 * @property TipoDocumentAsset $tipo
 * @property string $nombre
 * @property string $slug
 * @property int $version
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property string $sha256
 * @property AjusteFondo $fit_mode
 * @property int $default_opacity
 * @property string|null $safe_area_top_mm
 * @property string|null $safe_area_right_mm
 * @property string|null $safe_area_bottom_mm
 * @property string|null $safe_area_left_mm
 * @property bool $activo
 * @property int|null $reemplaza_a_id
 * @property int|null $creado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentAsset extends Model
{
    protected $table = 'document_assets';

    protected $fillable = [
        'tipo', 'nombre', 'slug', 'version', 'disk', 'path', 'mime_type', 'width', 'height', 'sha256',
        'fit_mode', 'default_opacity', 'safe_area_top_mm', 'safe_area_right_mm', 'safe_area_bottom_mm',
        'safe_area_left_mm', 'activo', 'reemplaza_a_id', 'creado_por',
    ];

    /** El disco/ruta nunca se exponen: la imagen se sirve por ruta autorizada. */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumentAsset::class,
            'fit_mode' => AjusteFondo::class,
            'version' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'default_opacity' => 'integer',
            'safe_area_top_mm' => 'decimal:2',
            'safe_area_right_mm' => 'decimal:2',
            'safe_area_bottom_mm' => 'decimal:2',
            'safe_area_left_mm' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function extension(): string
    {
        return match ($this->mime_type) {
            'image/jpeg' => 'jpeg',
            default => 'png',
        };
    }
}
