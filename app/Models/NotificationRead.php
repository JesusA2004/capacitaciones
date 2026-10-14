<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lectura genérica por usuario para fuentes del feed unificado que no
 * tienen su propia tabla de lectura (hoy, celebraciones globales —
 * App\Models\BirthdayGreeting). Ver App\Services\Notificaciones\NotificationFeedService.
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property int $user_id
 * @property Carbon $read_at
 */
class NotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['source_type', 'source_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
