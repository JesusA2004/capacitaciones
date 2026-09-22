<?php

namespace App\Models;

use Database\Factories\PrestamoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Préstamo interno real de un colaborador (ver App\Services\Nomina\PrestamoService).
 * Nace casi siempre de una SolicitudInterna de tipo `prestamo` aprobada
 * (`solicitud_id`); `saldo` es el saldo vivo, actualizado únicamente a
 * través de PrestamoMovimiento (ledger append-only) — nunca se decrementa
 * a mano fuera de PrestamoService::registrarMovimiento().
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $solicitud_id
 * @property string $monto_original
 * @property string $saldo
 * @property int $plazo
 * @property string $periodicidad
 * @property string $pago_programado
 * @property Carbon|null $fecha_otorgamiento
 * @property Carbon|null $fecha_primer_descuento
 * @property string $estado
 * @property string|null $monto_solicitado
 * @property int|null $plazo_solicitado
 * @property string|null $motivo
 * @property Carbon|null $fecha_solicitud
 * @property int|null $autorizado_por
 * @property Carbon|null $autorizado_en
 * @property string|null $observaciones
 * @property int|null $contrato_documento_id
 * @property int|null $pagare_documento_id
 * @property Carbon|null $resguardado_en
 * @property int|null $resguardado_por
 * @property Carbon|null $created_at
 * @property-read Colaborador $colaborador
 */
class Prestamo extends Model
{
    /** @use HasFactory<PrestamoFactory> */
    use HasFactory;

    protected $table = 'prestamos';

    protected $fillable = [
        'colaborador_id',
        'solicitud_id',
        'monto_original',
        'saldo',
        'plazo',
        'periodicidad',
        'pago_programado',
        'fecha_otorgamiento',
        'fecha_primer_descuento',
        'estado',
        'monto_solicitado',
        'plazo_solicitado',
        'motivo',
        'fecha_solicitud',
        'autorizado_por',
        'autorizado_en',
        'observaciones',
        'contrato_documento_id',
        'pagare_documento_id',
        'resguardado_en',
        'resguardado_por',
    ];

    protected function casts(): array
    {
        return [
            'monto_original' => 'decimal:2',
            'saldo' => 'decimal:2',
            'plazo' => 'integer',
            'pago_programado' => 'decimal:2',
            'fecha_otorgamiento' => 'date',
            'fecha_primer_descuento' => 'date',
            'monto_solicitado' => 'decimal:2',
            'plazo_solicitado' => 'integer',
            'fecha_solicitud' => 'date',
            'autorizado_en' => 'datetime',
            'resguardado_en' => 'datetime',
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
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function contratoDocumento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'contrato_documento_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function pagareDocumento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'pagare_documento_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autorizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    /**
     * @return HasMany<PrestamoMovimiento, $this>
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PrestamoMovimiento::class)->orderByDesc('fecha')->orderByDesc('id');
    }
}
