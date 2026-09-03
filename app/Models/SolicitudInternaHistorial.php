<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Timeline de una solicitud interna: una fila por cada cambio de estado o
 * comentario. `accion` usa los mismos valores que EstadoSolicitudInterna,
 * más 'comentario' para notas que no cambian el estado.
 *
 * @property int $id
 * @property int $solicitud_interna_id
 * @property int|null $user_id
 * @property string $accion
 * @property string|null $comentario
 */
class SolicitudInternaHistorial extends Model
{
    public $timestamps = false;

    protected $table = 'solicitud_interna_historial';

    protected $fillable = [
        'solicitud_interna_id',
        'user_id',
        'accion',
        'comentario',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
