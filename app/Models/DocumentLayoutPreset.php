<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Diseño base reutilizable de documentos maestros (p. ej. «Contrato
 * indeterminado MR. LANA»). Las familias lo referencian y pueden
 * sobrescribir partes (DocumentFamilyLayout); no se copia el JSON en cada
 * familia.
 *
 * @property int $id
 * @property string $slug
 * @property string $nombre
 * @property array<string, mixed> $config
 * @property bool $activo
 */
class DocumentLayoutPreset extends Model
{
    protected $table = 'document_layout_presets';

    protected $fillable = ['slug', 'nombre', 'config', 'activo'];

    protected function casts(): array
    {
        return ['config' => 'array', 'activo' => 'boolean'];
    }

    /**
     * @return HasMany<DocumentFamilyLayout, $this>
     */
    public function familias(): HasMany
    {
        return $this->hasMany(DocumentFamilyLayout::class, 'preset_id');
    }
}
