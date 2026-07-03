<?php

namespace App\Models;

use Database\Factories\IntervaloVideoVistoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo (en segundos) de una leccion de video que un usuario ya vio de
 * verdad. ReproduccionVideoService mantiene estos tramos fusionados (sin
 * solapes) para poder calcular tanto el porcentaje unico visto como el
 * limite de avance permitido sin depender de lo que el reproductor reporte
 * como "ya visto" en el cliente.
 *
 * @property int $id
 * @property int $user_id
 * @property int $leccion_id
 * @property int $inicio_segundo
 * @property int $fin_segundo
 */
class IntervaloVideoVisto extends Model
{
    /** @use HasFactory<IntervaloVideoVistoFactory> */
    use HasFactory;

    protected $table = 'intervalos_video_vistos';

    protected $fillable = ['user_id', 'leccion_id', 'inicio_segundo', 'fin_segundo'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Leccion, $this>
     */
    public function leccion(): BelongsTo
    {
        return $this->belongsTo(Leccion::class, 'leccion_id');
    }
}
