<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diseño de una familia de documentos maestros: preset base + overrides
 * propios de la familia (herencia preset → familia → versión).
 *
 * @property int $id
 * @property string $familia
 * @property int|null $preset_id
 * @property array<string, mixed>|null $overrides
 * @property int|null $actualizado_por
 * @property-read DocumentLayoutPreset|null $preset
 */
class DocumentFamilyLayout extends Model
{
    protected $table = 'document_family_layouts';

    protected $fillable = ['familia', 'preset_id', 'overrides', 'actualizado_por'];

    protected function casts(): array
    {
        return ['overrides' => 'array'];
    }

    /**
     * @return BelongsTo<DocumentLayoutPreset, $this>
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(DocumentLayoutPreset::class, 'preset_id');
    }
}
