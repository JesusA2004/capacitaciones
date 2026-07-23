<?php

namespace App\Models;

use App\Enums\EstadoDocumento;
use Database\Factories\EmployeeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un documento cargado al expediente de un colaborador. El archivo en si
 * nunca vive en la base de datos: `disk`/`path` apuntan al disco NAS (ver
 * App\Services\Expedientes\DocumentoStorageService, unica puerta de entrada
 * al disco). Cada nueva version es una fila nueva enlazada por
 * previous_version_id, en vez de una tabla separada de versiones: el
 * historial completo de un tipo de documento para un colaborador es
 * `EmployeeDocument::where('user_id', ..)->where('document_type_id', ..)`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int $document_type_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $stored_name
 * @property string|null $mime
 * @property string|null $extension
 * @property int|null $size
 * @property string|null $hash
 * @property int $version
 * @property int|null $previous_version_id
 * @property EstadoDocumento $status
 * @property int|null $uploaded_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $comments
 * @property string|null $rejection_reason
 */
class EmployeeDocument extends Model
{
    /** @use HasFactory<EmployeeDocumentFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'employee_documents';

    protected $fillable = [
        'user_id', 'empresa_id', 'sucursal_id', 'document_type_id',
        'disk', 'path', 'original_name', 'stored_name', 'mime', 'extension', 'size', 'hash',
        'version', 'previous_version_id', 'status',
        'uploaded_by', 'reviewed_by', 'reviewed_at', 'comments', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => EstadoDocumento::class,
            'reviewed_at' => 'datetime',
            'size' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function versionAnterior(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'previous_version_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'document_type_id', 'status', 'version', 'reviewed_by', 'rejection_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
