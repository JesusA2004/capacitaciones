<?php

namespace App\Models;

use App\Enums\CategoriaDocumento;
use App\Enums\MotorPlantilla;
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
 * @property int|null $departamento_id
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property string|null $mime
 * @property int|null $size
 * @property int $version
 * @property bool $activo
 * @property int|null $created_by
 * @property string|null $clave
 * @property CategoriaDocumento|null $categoria
 * @property MotorPlantilla $motor
 * @property string|null $contenido_html
 * @property int|null $official_format_id
 * @property int|null $document_type_id
 * @property bool $requiere_firma_digital
 * @property bool $requiere_impresion
 * @property bool $requiere_firma_fisica
 * @property bool $requiere_huella
 * @property bool $requiere_testigos
 * @property-read OfficialFormat|null $formatoOficial
 * @property-read DocumentType|null $tipoDocumento
 * @property-read int $documentos_generados_count Solo presente cuando se pide con withCount('documentosGenerados').
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
        'departamento_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'version',
        'activo',
        'created_by',
        'clave',
        'categoria',
        'motor',
        'contenido_html',
        'official_format_id',
        'document_type_id',
        'requiere_firma_digital',
        'requiere_impresion',
        'requiere_firma_fisica',
        'requiere_huella',
        'requiere_testigos',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'motor' => 'docx',
        'requiere_firma_digital' => false,
        'requiere_impresion' => false,
        'requiere_firma_fisica' => false,
        'requiere_huella' => false,
        'requiere_testigos' => false,
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPlantillaDocumento::class,
            'categoria' => CategoriaDocumento::class,
            'motor' => MotorPlantilla::class,
            'requiere_firma_digital' => 'boolean',
            'requiere_impresion' => 'boolean',
            'requiere_firma_fisica' => 'boolean',
            'requiere_huella' => 'boolean',
            'requiere_testigos' => 'boolean',
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
     * @return BelongsTo<Departamento, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<OfficialFormat, $this>
     */
    public function formatoOficial(): BelongsTo
    {
        return $this->belongsTo(OfficialFormat::class, 'official_format_id');
    }

    /**
     * Tipo documental del expediente con el que se archiva el escaneo final
     * firmado de los documentos emitidos con esta plantilla.
     *
     * @return BelongsTo<DocumentType, $this>
     */
    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documentosGenerados(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'document_template_id');
    }
}
