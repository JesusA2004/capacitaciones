<?php

namespace App\Models;

use App\Enums\TipoConceptoNomina;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Concepto capturado manualmente por RH en un finiquito (percepción o
 * deducción), además de los conceptos automáticos que ya calcula
 * App\Services\Finiquitos\FiniquitoService. Ver FiniquitoService::desglose().
 *
 * @property int $id
 * @property int $finiquito_calculo_id
 * @property TipoConceptoNomina $tipo
 * @property string $concepto
 * @property string $cantidad
 * @property string $importe
 * @property string|null $observaciones
 * @property int|null $capturado_por
 */
class FiniquitoConcepto extends Model
{
    protected $table = 'finiquito_conceptos';

    protected $fillable = ['finiquito_calculo_id', 'tipo', 'concepto', 'cantidad', 'importe', 'observaciones', 'capturado_por'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoConceptoNomina::class,
            'cantidad' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<FiniquitoCalculo, $this>
     */
    public function finiquito(): BelongsTo
    {
        return $this->belongsTo(FiniquitoCalculo::class, 'finiquito_calculo_id');
    }
}
