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
 * (INE, CURP, RFC, NSS, comprobante de domicilio, acta de nacimiento). Nunca
 * aprueba ni corrige datos del colaborador por si sola: es una propuesta que
 * RH revisa (aceptar/corregir/ignorar) — ver
 * App\Services\Documentos\DocumentExtractionService y docs/DOCUMENT_EXTRACTION.md.
 *
 * @property int $id
 * @property int $employee_document_id
 * @property int $user_id
 * @property EstadoExtraccion $status
 * @property string|null $extracted_text
 * @property array<string, string>|null $extracted_data
 * @property array<string, string>|null $confidence
 * @property array<string, array<string, mixed>>|null $differences
 * @property string|null $error_message
 * @property int|null $reviewed_by_id
 * @property Carbon|null $reviewed_at
 */
class DocumentExtraction extends Model
{
    /** @use HasFactory<DocumentExtractionFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_document_id',
        'user_id',
        'status',
        'extracted_text',
        'extracted_data',
        'confidence',
        'differences',
        'error_message',
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
     * @return BelongsTo<User, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
