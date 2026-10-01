<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Intento de evaluación de un módulo de onboarding. Se conservan todos (el
 * historial completo de calificaciones), nunca se sobrescribe uno anterior.
 *
 * @property int $id
 * @property int $onboarding_avance_id
 * @property int $numero
 * @property string $calificacion
 * @property bool $aprobado
 * @property array<int|string, int>|null $respuestas
 * @property string|null $retroalimentacion_previa
 * @property int|null $presentado_por
 * @property Carbon|null $created_at
 */
class OnboardingIntento extends Model
{
    protected $table = 'onboarding_intentos';

    protected $fillable = ['onboarding_avance_id', 'numero', 'calificacion', 'aprobado', 'respuestas', 'retroalimentacion_previa', 'presentado_por'];

    protected function casts(): array
    {
        return [
            'calificacion' => 'decimal:2',
            'aprobado' => 'boolean',
            'respuestas' => 'array',
            'numero' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OnboardingAvance, $this>
     */
    public function avance(): BelongsTo
    {
        return $this->belongsTo(OnboardingAvance::class, 'onboarding_avance_id');
    }
}
