<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de correcciones de rotación: cada cambio a la causal homologada de
 * una baja, a la fecha de ingreso de una baja histórica o al mapeo de una
 * causal del catálogo, con antes, después, comentario obligatorio, quién y
 * cuándo. Solo se agregan filas: nunca se editan ni se borran.
 *
 * @property int $id
 * @property string $sujeto_tipo baja | baja_historica | causal_rotacion
 * @property int $sujeto_id
 * @property string $campo
 * @property string|null $antes
 * @property string|null $despues
 * @property string $comentario
 * @property int|null $usuario_id
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
class RotacionCorreccion extends Model
{
    protected $table = 'rotacion_correcciones';

    public const UPDATED_AT = null;

    protected $fillable = ['sujeto_tipo', 'sujeto_id', 'campo', 'antes', 'despues', 'comentario', 'usuario_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
