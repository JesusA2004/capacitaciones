<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anexo (evidencia) de un acta administrativa. Solo metadatos: el archivo
 * vive en el disco del expediente (categoría Actas).
 *
 * @property int $id
 * @property int $acta_administrativa_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $checksum
 * @property string|null $descripcion
 * @property int|null $subido_por
 */
class ActaAnexo extends Model
{
    protected $table = 'acta_anexos';

    protected $hidden = ['disk', 'path'];

    protected $fillable = ['acta_administrativa_id', 'disk', 'path', 'original_name', 'mime', 'size', 'checksum', 'descripcion', 'subido_por'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<ActaAdministrativa, $this>
     */
    public function acta(): BelongsTo
    {
        return $this->belongsTo(ActaAdministrativa::class, 'acta_administrativa_id');
    }
}
