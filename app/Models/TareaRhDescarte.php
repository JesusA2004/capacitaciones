<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «Quitar de mi lista»: un pendiente (tareas_rh) que una persona descartó
 * PARA SÍ, con motivo opcional. No cierra el pendiente para nadie más.
 *
 * @property int $id
 * @property int $tarea_rh_id
 * @property int $user_id
 * @property string|null $motivo
 * @property Carbon|null $created_at
 * @property-read TareaRh|null $tarea
 */
class TareaRhDescarte extends Model
{
    protected $table = 'tarea_rh_descartes';

    protected $fillable = ['tarea_rh_id', 'user_id', 'motivo'];

    /**
     * @return BelongsTo<TareaRh, $this>
     */
    public function tarea(): BelongsTo
    {
        return $this->belongsTo(TareaRh::class, 'tarea_rh_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
