<?php

namespace App\Models;

use App\Enums\EstadoLoteNomina;
use App\Enums\PeriodicidadNomina;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lote de recibos de nómina (App\Services\Nomina\LoteNominaService):
 * PREPARAR → REVISAR → EMITIR. Conserva el histórico del lote (periodo,
 * quién lo creó, cuántos se esperaban/prepararon, errores, advertencias,
 * totales, emisión y cancelación) sin depender del archivo importado.
 *
 * @property int $id
 * @property string $folio
 * @property PeriodicidadNomina $periodicidad
 * @property Carbon $periodo_inicio
 * @property Carbon $periodo_fin
 * @property Carbon $fecha_pago
 * @property int|null $numero_nomina
 * @property string $origen
 * @property string|null $archivo_nombre
 * @property EstadoLoteNomina $estado
 * @property int $esperados
 * @property int $preparados
 * @property list<array{numero_empleado: string|null, colaborador: string|null, motivo: string, fila?: int|null}>|null $errores
 * @property list<array{numero_empleado: string|null, colaborador: string|null, motivo: string}>|null $advertencias
 * @property string $total_percepciones
 * @property string $total_deducciones
 * @property string $total_neto
 * @property int|null $creado_por
 * @property int|null $emitido_por
 * @property Carbon|null $emitido_at
 * @property int|null $cancelado_por
 * @property Carbon|null $cancelado_at
 * @property string|null $motivo_cancelacion
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creadoPor
 * @property-read User|null $emitidoPor
 * @property-read User|null $canceladoPor
 */
class NominaLote extends Model
{
    public const ORIGEN_SUELDOS = 'sueldos';

    public const ORIGEN_IMPORTACION = 'importacion';

    protected $table = 'nomina_lotes';

    protected $fillable = [
        'folio', 'periodicidad', 'periodo_inicio', 'periodo_fin', 'fecha_pago', 'numero_nomina', 'origen', 'archivo_nombre',
        'estado', 'esperados', 'preparados', 'errores', 'advertencias', 'total_percepciones', 'total_deducciones', 'total_neto',
        'creado_por', 'emitido_por', 'emitido_at', 'cancelado_por', 'cancelado_at', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'periodicidad' => PeriodicidadNomina::class,
            'estado' => EstadoLoteNomina::class,
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
            'fecha_pago' => 'date',
            'numero_nomina' => 'integer',
            'esperados' => 'integer',
            'preparados' => 'integer',
            'errores' => 'array',
            'advertencias' => 'array',
            'total_percepciones' => 'decimal:2',
            'total_deducciones' => 'decimal:2',
            'total_neto' => 'decimal:2',
            'emitido_at' => 'datetime',
            'cancelado_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ReciboNomina, $this>
     */
    public function recibos(): HasMany
    {
        return $this->hasMany(ReciboNomina::class, 'nomina_lote_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function emitidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitido_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }
}
