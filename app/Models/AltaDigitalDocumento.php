<?php

namespace App\Models;

use Database\Factories\AltaDigitalDocumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $alta_digital_id
 * @property int $document_type_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int|null $size
 */
class AltaDigitalDocumento extends Model
{
    /** @use HasFactory<AltaDigitalDocumentoFactory> */
    use HasFactory;

    protected $table = 'alta_digital_documentos';

    protected $hidden = ['disk', 'path'];

    protected $fillable = [
        'alta_digital_id',
        'document_type_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
    ];

    /**
     * @return BelongsTo<AltaDigital, $this>
     */
    public function altaDigital(): BelongsTo
    {
        return $this->belongsTo(AltaDigital::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
