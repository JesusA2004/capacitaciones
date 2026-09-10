<?php

namespace App\Models;

use Database\Factories\MobileDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un dispositivo movil (telefono) donde un usuario inicio sesion en la app,
 * con su push token de Expo vigente. Ver App\Services\MobilePush\ExpoPushService
 * y docs/PUSH_NOTIFICATIONS.md.
 *
 * @property int $id
 * @property int $user_id
 * @property string $push_token
 * @property string $platform
 * @property string|null $device_name
 * @property string|null $app_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
class MobileDevice extends Model
{
    /** @use HasFactory<MobileDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'push_token',
        'platform',
        'device_name',
        'app_version',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<MobileDevice>  $query
     * @return Builder<MobileDevice>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
