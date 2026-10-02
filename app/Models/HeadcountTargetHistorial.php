<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un cambio de plantilla autorizada (captura de RH o importación del
 * Excel). Solo se inserta: es la bitácora de quién movió la plantilla.
 *
 * @property int $id
 * @property int|null $headcount_target_id
 * @property int $sucursal_id
 * @property int $puesto_id
 * @property int|null $valor_anterior
 * @property int $valor_nuevo
 * @property string $fuente
 * @property string|null $motivo
 * @property int|null $user_id
 * @property Carbon $created_at
 */
class HeadcountTargetHistorial extends Model
{
    protected $table = 'headcount_target_historial';

    protected $fillable = [
        'headcount_target_id',
        'sucursal_id',
        'puesto_id',
        'valor_anterior',
        'valor_nuevo',
        'fuente',
        'motivo',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'valor_anterior' => 'integer',
            'valor_nuevo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
