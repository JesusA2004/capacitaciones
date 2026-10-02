<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A quién se AVISA de un evento (destinatarios dinámicos). Nunca decide
 * quién autoriza. Ver App\Services\Configuracion\WorkflowRoutingService.
 *
 * @property int $id
 * @property string $evento
 * @property list<string> $destinatarios
 * @property string|null $permiso
 * @property list<int>|null $usuario_ids
 * @property list<string>|null $fallback
 * @property bool $activa
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReglaNotificacion extends Model
{
    protected $table = 'reglas_notificacion';

    protected $fillable = ['evento', 'destinatarios', 'permiso', 'usuario_ids', 'fallback', 'activa', 'actualizado_por'];

    protected function casts(): array
    {
        return [
            'destinatarios' => 'array',
            'usuario_ids' => 'array',
            'fallback' => 'array',
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
