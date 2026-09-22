<?php

namespace App\Models;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumentoGenerado;
use App\Enums\EstadoFlujoDocumento;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Documento precargado generado a partir de una DocumentTemplate para un
 * colaborador, candidato o solicitud. El archivo vive en el disco 'nas'
 * (App\Services\Plantillas\PlantillaStorageService); esta tabla solo guarda
 * metadatos.
 *
 * @property int $id
 * @property int|null $document_template_id
 * @property int|null $user_id
 * @property int|null $colaborador_id
 * @property int|null $candidato_id
 * @property int|null $solicitud_id
 * @property int|null $solicitud_vacaciones_id
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
 * @property string|null $documentable_type
 * @property int|null $documentable_id
 * @property string|null $clave_plantilla
 * @property int|null $version_plantilla
 * @property CategoriaDocumento|null $categoria
 * @property string|null $titulo
 * @property array<string, string>|null $payload
 * @property string|null $checksum
 * @property EstadoFlujoDocumento|null $estado_flujo
 * @property bool $requiere_firma_digital
 * @property bool $requiere_impresion
 * @property bool $requiere_firma_fisica
 * @property bool $requiere_huella
 * @property bool $requiere_testigos
 * @property Carbon|null $firmado_digital_en
 * @property int|null $firmado_digital_por
 * @property string|null $firma_digital_ip
 * @property string|null $firma_digital_user_agent
 * @property string|null $firma_digital_hash
 * @property string|null $motivo_cancelacion
 * @property Carbon|null $created_at
 * @property-read Colaborador|null $colaborador
 * @property-read DocumentTemplate|null $plantilla
 * @property-read SeguimientoDocumentoFisico|null $seguimientoFisico
 */
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use HasFactory;

    protected $hidden = ['disk', 'path'];

    protected $fillable = [
        'document_template_id',
        'user_id',
        'colaborador_id',
        'candidato_id',
        'solicitud_id',
        'solicitud_vacaciones_id',
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
        'documentable_type',
        'documentable_id',
        'clave_plantilla',
        'version_plantilla',
        'categoria',
        'titulo',
        'payload',
        'checksum',
        'estado_flujo',
        'requiere_firma_digital',
        'requiere_impresion',
        'requiere_firma_fisica',
        'requiere_huella',
        'requiere_testigos',
        'firmado_digital_en',
        'firmado_digital_por',
        'firma_digital_ip',
        'firma_digital_user_agent',
        'firma_digital_hash',
        'motivo_cancelacion',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'requiere_firma_digital' => false,
        'requiere_impresion' => false,
        'requiere_firma_fisica' => false,
        'requiere_huella' => false,
        'requiere_testigos' => false,
    ];

    /**
     * Objeto de negocio que originó el documento (contrato laboral,
     * préstamo, finiquito, acta, recibo, solicitud...).
     *
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<SeguimientoDocumentoFisico, $this>
     */
    public function seguimientoFisico(): HasOne
    {
        return $this->hasOne(SeguimientoDocumentoFisico::class, 'generated_document_id');
    }

    /**
     * @return HasMany<DocumentoEvento, $this>
     */
    public function eventos(): HasMany
    {
        return $this->hasMany(DocumentoEvento::class, 'generated_document_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'status' => EstadoDocumentoGenerado::class,
            'estado_flujo' => EstadoFlujoDocumento::class,
            'categoria' => CategoriaDocumento::class,
            'payload' => 'array',
            'requiere_firma_digital' => 'boolean',
            'requiere_impresion' => 'boolean',
            'requiere_firma_fisica' => 'boolean',
            'requiere_huella' => 'boolean',
            'requiere_testigos' => 'boolean',
            'firmado_digital_en' => 'datetime',
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
     * Colaborador (persona/empleo) para quien se generó este documento —
     * fuente de verdad preferida sobre usuario()/user_id, que ahora es
     * legacy (ver docs/ROLES_Y_NAVEGACION.md).
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_id');
    }

    /**
     * @return BelongsTo<SolicitudVacaciones, $this>
     */
    public function solicitudVacaciones(): BelongsTo
    {
        return $this->belongsTo(SolicitudVacaciones::class, 'solicitud_vacaciones_id');
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
