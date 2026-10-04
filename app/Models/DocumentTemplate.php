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
use Illuminate\Support\Carbon;

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
 * @property array<int, array<string, mixed>>|null $variables_manuales Cada elemento: {clave, etiqueta, descripcion, tipo, requerido, valor_por_defecto, opciones}.
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
 * @property string|null $familia Línea del documento maestro (p. ej. contrato_capacitacion.gestor); versiones 1..n, una activa.
 * @property list<string>|null $grupos_puesto Grupos documentales a los que aplica (null = general).
 * @property string|null $proceso
 * @property string|null $evento
 * @property string|null $causa
 * @property string|null $original_disk
 * @property string|null $original_path ORIGINAL inmutable entregado por Jurídico.
 * @property string|null $original_hash
 * @property string|null $original_nombre
 * @property string|null $master_hash
 * @property array<string, mixed>|null $mapping
 * @property array<string, mixed>|null $analisis
 * @property string|null $estado_master listo | con_pendientes | referencia | bloqueado
 * @property bool $operativo
 * @property bool $requiere_envio_corporativo
 * @property int $cantidad_testigos
 * @property int $orden
 * @property int $prioridad_especificidad
 * @property list<array{nombre: string, sha256: string}>|null $fuentes
 * @property string|null $observaciones
 * @property Carbon|null $ultima_prueba_en
 * @property int|null $ultima_prueba_por
 * @property array<string, mixed>|null $ultima_prueba_resultado
 * @property-read OfficialFormat|null $formatoOficial
 * @property-read DocumentType|null $tipoDocumento
 * @property-read int $documentos_generados_count Solo presente cuando se pide con withCount('documentosGenerados').
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory, SoftDeletes;

    protected $hidden = ['disk', 'path', 'original_disk', 'original_path'];

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
        'variables_manuales',
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
        'familia',
        'grupos_puesto',
        'proceso',
        'evento',
        'causa',
        'original_disk',
        'original_path',
        'original_hash',
        'original_nombre',
        'master_hash',
        'mapping',
        'analisis',
        'estado_master',
        'operativo',
        'requiere_envio_corporativo',
        'cantidad_testigos',
        'orden',
        'prioridad_especificidad',
        'fuentes',
        'observaciones',
        'ultima_prueba_en',
        'ultima_prueba_por',
        'ultima_prueba_resultado',
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
        'operativo' => true,
        'requiere_envio_corporativo' => false,
        'cantidad_testigos' => 0,
        'orden' => 0,
        'prioridad_especificidad' => 0,
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
            'variables_manuales' => 'array',
            'grupos_puesto' => 'array',
            'mapping' => 'array',
            'analisis' => 'array',
            'fuentes' => 'array',
            'operativo' => 'boolean',
            'requiere_envio_corporativo' => 'boolean',
            'cantidad_testigos' => 'integer',
            'orden' => 'integer',
            'prioridad_especificidad' => 'integer',
            'ultima_prueba_en' => 'datetime',
            'ultima_prueba_resultado' => 'array',
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
