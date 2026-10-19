<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo (en segundos) del VIDEO de la Inducción Mr. Lana que un colaborador
 * ya vio de verdad, por avance de onboarding. Espejo dedicado de
 * IntervaloVideoVisto (Capacitación) para NO tocar el módulo congelado:
 * App\Services\Onboarding\ReproduccionVideoInduccionService mantiene estos
 * tramos fusionados (sin solapes) para calcular el porcentaje único visto y el
 * límite de avance permitido respaldado por el servidor (anti-adelanto).
 *
 * @property int $id
 * @property int $user_id
 * @property int $onboarding_avance_id
 * @property int $inicio_segundo
 * @property int $fin_segundo
 */
class IntervaloVideoInduccion extends Model
{
    protected $table = 'induccion_video_intervalos';

    protected $fillable = ['user_id', 'onboarding_avance_id', 'inicio_segundo', 'fin_segundo'];

    protected function casts(): array
    {
        return [
            'inicio_segundo' => 'integer',
            'fin_segundo' => 'integer',
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
     * @return BelongsTo<OnboardingAvance, $this>
     */
    public function avance(): BelongsTo
    {
        return $this->belongsTo(OnboardingAvance::class, 'onboarding_avance_id');
    }
}
