<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bitácora de una cita (solo se agrega, nunca se edita).
 *
 * @property int $id
 * @property int $agenda_evento_id
 * @property string $accion
 * @property string|null $estado_anterior
 * @property string|null $estado_nuevo
 * @property string|null $detalle
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
class AgendaEventoHistorial extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'agenda_evento_historial';

    protected $fillable = ['agenda_evento_id', 'accion', 'estado_anterior', 'estado_nuevo', 'detalle', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
