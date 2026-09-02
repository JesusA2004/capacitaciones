<?php

namespace App\Models;

use App\Enums\EstadoDocumentoGenerado;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento precargado generado a partir de una DocumentTemplate para un
 * colaborador, candidato o solicitud. El archivo vive en el disco 'nas'
 * (App\Services\Plantillas\PlantillaStorageService); esta tabla solo guarda
 * metadatos.
 *
 * @property int $id
 * @property int|null $document_template_id
 * @property int|null $user_id
 * @property int|null $candidato_id
 * @property int|null $solicitud_id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $generated_name
 * @property string|null $mime
 * @property int|null $size
 * @property EstadoDocumentoGenerado $status
 * @property int|null $generated_by
 * @property int|null $signed_document_id
 */
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use HasFactory;

    protected $hidden = ['disk', 'path'];

    protected $fillable = [
        'document_template_id',
        'user_id',
        'candidato_id',
        'solicitud_id',
        'empresa_id',
        'sucursal_id',
        'disk',
        'path',
        'original_name',
        'generated_name',
        'mime',
        'size',
        'status',
        'generated_by',
        'signed_document_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EstadoDocumentoGenerado::class,
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function documentoFirmado(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'signed_document_id');
    }
}
