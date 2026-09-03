<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un adjunto de una solicitud interna. El archivo vive en el disco NAS
 * (App\Services\Solicitudes\SolicitudDocumentoStorageService); esta tabla
 * solo guarda metadatos, igual criterio que EmployeeDocument/GeneratedDocument.
 *
 * @property int $id
 * @property int $solicitud_interna_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $stored_name
 * @property string|null $mime
 * @property int|null $size
 * @property int|null $subido_por
 */
class SolicitudInternaDocumento extends Model
{
    protected $table = 'solicitud_interna_documentos';

    protected $hidden = ['disk', 'path'];

    protected $fillable = [
        'solicitud_interna_id',
        'disk',
        'path',
        'original_name',
        'stored_name',
        'mime',
        'size',
        'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
