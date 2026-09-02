<?php

namespace App\Models;

use App\Enums\TipoPlantillaDocumento;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plantilla oficial (DOCX) que RH sube para generar documentos precargados.
 * El archivo original vive en el disco 'nas' (ver
 * App\Services\Plantillas\PlantillaStorageService); esta tabla solo guarda
 * metadatos, igual que document_types/employee_documents.
 *
 * @property int $id
 * @property string $nombre
 * @property TipoPlantillaDocumento $tipo
 * @property string|null $descripcion
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $puesto_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int|null $size
 * @property int $version
 * @property bool $activo
 * @property int|null $created_by
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory, SoftDeletes;

    protected $hidden = ['disk', 'path'];

    protected $fillable = [
        'nombre',
        'tipo',
        'descripcion',
        'empresa_id',
        'sucursal_id',
        'puesto_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'version',
        'activo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPlantillaDocumento::class,
            'activo' => 'boolean',
            'version' => 'integer',
            'size' => 'integer',
        ];
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
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documentosGenerados(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'document_template_id');
    }
}
