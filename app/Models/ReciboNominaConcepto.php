<?php

namespace App\Models;

use App\Enums\TipoConceptoNomina;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de detalle de un recibo interno de nómina (NO fiscal). Los
 * importes ya son finales: el sistema no calcula ISR/IMSS.
 *
 * @property int $id
 * @property int $recibo_nomina_id
 * @property TipoConceptoNomina $tipo
 * @property string $concepto
 * @property string $cantidad
 * @property string $importe
 * @property string|null $observaciones
 * @property int $orden
 */
class ReciboNominaConcepto extends Model
{
    protected $table = 'recibo_nomina_conceptos';

    protected $fillable = ['recibo_nomina_id', 'tipo', 'concepto', 'cantidad', 'importe', 'observaciones', 'orden'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoConceptoNomina::class,
            'cantidad' => 'decimal:2',
            'importe' => 'decimal:2',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReciboNomina, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(ReciboNomina::class, 'recibo_nomina_id');
    }
}
