<?php

namespace App\Models;

use App\Enums\NivelNineBox;
use App\Enums\TipoPeriodoNineBox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Calibración del potencial que captura el jefe directo o RH para un
 * colaborador en un periodo de la Nine Box. Historial completo: recalibrar
 * inserta otra fila; nunca se borra. La vigente para un periodo es la más
 * reciente cuyo `periodo_fin` no es posterior al fin de ese periodo
 * (docs/NINE_BOX.md).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property TipoPeriodoNineBox $periodo_tipo
 * @property string $periodo_clave
 * @property string $periodo_etiqueta
 * @property Carbon $periodo_fin
 * @property NivelNineBox $nivel
 * @property string $comentario
 * @property int|null $capturada_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 * @property-read User|null $capturadaPor
 */
class NineBoxCalibracion extends Model
{
    protected $table = 'nine_box_calibraciones';

    protected $fillable = [
        'colaborador_id', 'periodo_tipo', 'periodo_clave', 'periodo_etiqueta',
        'periodo_fin', 'nivel', 'comentario', 'capturada_por',
    ];

    protected function casts(): array
    {
        return [
            'periodo_tipo' => TipoPeriodoNineBox::class,
            'periodo_fin' => 'date',
            'nivel' => NivelNineBox::class,
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
     * @return BelongsTo<User, $this>
     */
    public function capturadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturada_por');
    }
}
