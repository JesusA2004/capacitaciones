<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Suscripción Web Push de un navegador donde el usuario activó las
 * notificaciones de escritorio (equivalente web de MobileDevice). Ver
 * App\Services\WebPush\WebPushNotifier y docs/PUSH_NOTIFICATIONS.md.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $clave_publica
 * @property string $token_autenticacion
 * @property string $codificacion
 * @property string|null $navegador
 * @property Carbon|null $ultimo_envio_en
 */
class WebPushSuscripcion extends Model
{
    protected $table = 'web_push_suscripciones';

    /** Las llaves de cifrado de la suscripción nunca viajan en una respuesta JSON. */
    protected $hidden = ['endpoint', 'endpoint_hash', 'clave_publica', 'token_autenticacion'];

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'clave_publica',
        'token_autenticacion',
        'codificacion',
        'navegador',
        'ultimo_envio_en',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_envio_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function hashDe(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
