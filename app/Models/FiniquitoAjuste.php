<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ajuste autorizado por RH a un concepto AUTOMÁTICO del finiquito (sueldo
 * pendiente, aguinaldo, vacaciones, prima, ISR…): guarda el valor que
 * calculó el sistema, el valor final autorizado, la diferencia, el motivo,
 * quién y cuándo. Solo se agrega (nunca se edita): el último ajuste de cada
 * concepto es el vigente y los anteriores quedan como historial.
 *
 * @property int $id
 * @property int $finiquito_calculo_id
 * @property string $concepto_clave
 * @property string $concepto
 * @property string $valor_calculado
 * @property string $valor_final
 * @property string $ajuste
 * @property string $motivo
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
class FiniquitoAjuste extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'finiquito_ajustes';

    protected $fillable = ['finiquito_calculo_id', 'concepto_clave', 'concepto', 'valor_calculado', 'valor_final', 'ajuste', 'motivo', 'user_id'];

    protected function casts(): array
    {
        return [
            'valor_calculado' => 'decimal:2',
            'valor_final' => 'decimal:2',
            'ajuste' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiniquitoCalculo, $this>
     */
    public function finiquito(): BelongsTo
    {
        return $this->belongsTo(FiniquitoCalculo::class, 'finiquito_calculo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
