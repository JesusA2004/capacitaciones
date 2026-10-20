<?php

namespace App\Models;

use App\Enums\AccionBitacoraBaja;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bitácora de una baja (RN-13): usuario, fecha, acción, valor anterior y
 * nuevo. Solo se agrega, nunca se edita.
 *
 * @property int $id
 * @property int $baja_id
 * @property int|null $user_id
 * @property AccionBitacoraBaja $accion
 * @property string|null $campo
 * @property string|null $valor_anterior
 * @property string|null $valor_nuevo
 * @property string|null $motivo
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
class BajaBitacora extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'baja_bitacora';

    protected $fillable = ['baja_id', 'user_id', 'accion', 'campo', 'valor_anterior', 'valor_nuevo', 'motivo'];

    protected function casts(): array
    {
        return ['accion' => AccionBitacoraBaja::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
