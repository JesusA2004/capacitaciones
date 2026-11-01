<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio del checklist de avisos: quién, cuándo, antes y después.
 *
 * @property int $id
 * @property int $role_id
 * @property string $evento
 * @property bool $antes
 * @property bool $despues
 * @property int|null $actor_id
 * @property CarbonInterface|null $created_at
 * @property-read User|null $actor
 */
class NotificacionEventoRolHistorial extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notificacion_evento_rol_historial';

    protected $fillable = ['role_id', 'evento', 'antes', 'despues', 'actor_id'];

    protected function casts(): array
    {
        return ['antes' => 'boolean', 'despues' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
