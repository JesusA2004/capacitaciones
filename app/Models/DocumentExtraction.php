<?php

namespace App\Models;

use App\Enums\EstadoExtraccion;
use Database\Factories\DocumentExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sugerencias de datos detectados automaticamente en un EmployeeDocument
 * (INE, CURP, RFC, NSS, comprobante de domicilio, acta de nacimiento) o en un
 * ExpedienteHistorico (PDF único de la migración inicial) — exactamente una
 * de las dos FKs tiene valor, nunca ambas. Nunca
 * aprueba ni corrige datos del colaborador por si sola: es una propuesta que
 * RH revisa (aceptar/corregir/ignorar) — ver
 * App\Services\Documentos\DocumentExtractionService y docs/DOCUMENT_EXTRACTION.md.
 *
 * @property int $id
 * @property int|null $employee_document_id
 * @property int|null $expediente_historico_id
 * @property int|null $user_id
 * @property int|null $colaborador_id
 * @property EstadoExtraccion $status
 * @property string|null $extracted_text
 * @property array<string, string>|null $extracted_data
 * @property array<string, string>|null $confidence
 * @property array<string, array<string, mixed>>|null $differences
 * @property string|null $error_message
 * @property string|null $source_hash
 * @property int|null $extractor_version
 * @property bool $ocr_used
 * @property int|null $reviewed_by_id
 * @property Carbon|null $reviewed_at
 */
class DocumentExtraction extends Model
{
    /** @use HasFactory<DocumentExtractionFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_document_id',
        'expediente_historico_id',
        'user_id',
        'colaborador_id',
        'status',
        'extracted_text',
        'extracted_data',
        'confidence',
        'differences',
        'error_message',
        'source_hash',
        'extractor_version',
        'ocr_used',
        'reviewed_by_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EstadoExtraccion::class,
            'extracted_data' => 'array',
            'confidence' => 'array',
            'differences' => 'array',
            'ocr_used' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    /**
     * @return BelongsTo<ExpedienteHistorico, $this>
     */
    public function expedienteHistorico(): BelongsTo
    {
        return $this->belongsTo(ExpedienteHistorico::class, 'expediente_historico_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * El Colaborador real dueño del expediente (no el User de colaborador()
     * de arriba) — misma fuente que EmployeeDocument::colaborador()/
     * ExpedienteHistorico::colaborador(). Se resuelve por columna propia (no
     * vía documento()->colaborador()) porque una extracción de un histórico
     * no tiene EmployeeDocument.
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaboradorExpediente(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** 'documento' | 'historico' — para mostrar la fuente de la sugerencia en el panel RH (encargo §20). */
    public function fuenteTipo(): string
    {
        return $this->expediente_historico_id !== null ? 'historico' : 'documento';
    }

    /** Nombre humano de la fuente: tipo de documento normal, o "Expediente histórico". */
    public function fuenteNombre(): string
    {
        if ($this->expediente_historico_id !== null) {
            return 'Expediente histórico';
        }

        return $this->documento?->tipo->nombre ?? 'Documento';
    }
}
