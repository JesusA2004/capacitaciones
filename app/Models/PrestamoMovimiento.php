<?php

namespace App\Models;

use Database\Factories\PrestamoMovimientoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un abono/ajuste al saldo de un Prestamo (ver App\Services\Nomina\PrestamoService::registrarMovimiento()).
 * Ledger APPEND-ONLY: esta tabla nunca se actualiza ni se borra un registro
 * ya creado — congela `saldo_anterior`/`saldo_nuevo` en el momento en que se
 * registró para que el historial sea auditable.
 *
 * @property int $id
 * @property int $prestamo_id
 * @property Carbon $fecha
 * @property string $monto
 * @property string $tipo
 * @property string $saldo_anterior
 * @property string $saldo_nuevo
 * @property int|null $registrado_por
 */
class PrestamoMovimiento extends Model
{
    /** @use HasFactory<PrestamoMovimientoFactory> */
    use HasFactory;

    protected $table = 'prestamo_movimientos';

    protected $fillable = [
        'prestamo_id',
        'fecha',
        'monto',
        'tipo',
        'saldo_anterior',
        'saldo_nuevo',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
            'saldo_anterior' => 'decimal:2',
            'saldo_nuevo' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Prestamo, $this>
     */
    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
