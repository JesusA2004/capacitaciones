<?php

namespace App\Models;

use App\Enums\EstadoCartaCompromiso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Carta compromiso de documentos pendientes (una sola carta por registro,
 * con TODOS los documentos que la persona marcó «No tengo este documento»).
 * Ver App\Services\Expedientes\CartaCompromisoService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property string|null $folio
 * @property EstadoCartaCompromiso $estado
 * @property list<array{document_type_id: int, clave: string, nombre: string}> $documentos
 * @property Carbon $fecha_limite
 * @property Carbon $generada_en
 * @property Carbon|null $cumplida_en
 * @property Carbon|null $vencida_en
 * @property Carbon|null $sustituida_en
 * @property Carbon|null $firmada_en Pendiente de firma mientras sea null.
 * @property int|null $firma_registrada_por
 * @property int|null $firmada_document_id Escaneo firmado en el expediente.
 * @property int|null $generada_por
 * @property int|null $generated_document_id
 * @property string|null $disk
 * @property string|null $path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 * @property-read GeneratedDocument|null $documentoGenerado
 * @property-read EmployeeDocument|null $documentoFirmado
 * @property-read User|null $generadaPor
 * @property-read User|null $firmaRegistradaPor
 */
class CartaCompromiso extends Model
{
    protected $table = 'cartas_compromiso';

    protected $fillable = [
        'colaborador_id',
        'folio',
        'estado',
        'documentos',
        'fecha_limite',
        'generada_en',
        'cumplida_en',
        'vencida_en',
        'sustituida_en',
        'firmada_en',
        'firma_registrada_por',
        'firmada_document_id',
        'generada_por',
        'generated_document_id',
        'disk',
        'path',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCartaCompromiso::class,
            'documentos' => 'array',
            'fecha_limite' => 'date',
            'generada_en' => 'datetime',
            'cumplida_en' => 'datetime',
            'vencida_en' => 'datetime',
            'sustituida_en' => 'datetime',
            'firmada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documentoGenerado(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function documentoFirmado(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'firmada_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generada_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firmaRegistradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firma_registrada_por');
    }

    /** ¿Ya se subió el escaneo firmado por quien entra? */
    public function firmada(): bool
    {
        return $this->firmada_en !== null;
    }

    /** ¿Ya pasó la fecha límite? (el día límite completo todavía cuenta). */
    public function plazoVencido(): bool
    {
        return $this->fecha_limite->copy()->endOfDay()->isPast();
    }

    /** Días que faltan para la fecha límite (0 si ya venció o vence hoy). */
    public function diasRestantes(): int
    {
        return max(0, (int) now()->startOfDay()->diffInDays($this->fecha_limite->copy()->startOfDay(), false));
    }
}
