<?php

namespace App\Models;

use App\Enums\CategoriaNotificacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una entrega de aviso (ver App\Services\Notificaciones\NotificacionEventosService).
 *
 * @property int $id
 * @property string $clave
 * @property int $user_id
 * @property CategoriaNotificacion $categoria
 * @property string $evento
 * @property string|null $related_type
 * @property int|null $related_id
 * @property string|null $accion
 * @property string|null $notification_id
 * @property int $push_dispositivos
 * @property string $origen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificacionEntrega extends Model
{
    protected $table = 'notificacion_entregas';

    protected $fillable = [
        'clave', 'user_id', 'categoria', 'evento', 'related_type', 'related_id',
        'accion', 'notification_id', 'push_dispositivos', 'origen',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaNotificacion::class,
            'related_id' => 'integer',
            'push_dispositivos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
