<?php

namespace App\Models;

use App\Enums\TipoReapertura;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Historial de una decisión reabierta: quién la tomó, quién la reabrió, por qué y
 * cómo estaba. Nunca se edita ni se borra.
 *
 * @property int $id
 * @property TipoReapertura $tipo
 * @property string $reabrible_type
 * @property int $reabrible_id
 * @property int|null $decidido_por
 * @property int $reabierto_por
 * @property string $motivo
 * @property array<string, mixed> $decision_anterior
 * @property Carbon|null $created_at
 */
class Reapertura extends Model
{
    protected $table = 'reaperturas';

    protected $fillable = ['tipo', 'reabrible_type', 'reabrible_id', 'decidido_por', 'reabierto_por', 'motivo', 'decision_anterior'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoReapertura::class,
            'decision_anterior' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reabrible(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reabiertoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reabierto_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }
}
