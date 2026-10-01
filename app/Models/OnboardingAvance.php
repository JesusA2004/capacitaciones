<?php

namespace App\Models;

use App\Enums\EstadoAvanceOnboarding;
use App\Enums\TipoModuloOnboarding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Avance de un colaborador en un módulo de onboarding (estado, intentos,
 * calificaciones y retroalimentación de RH).
 *
 * @property int $id
 * @property int $onboarding_proceso_id
 * @property int $onboarding_modulo_id
 * @property TipoModuloOnboarding $tipo
 * @property int $orden
 * @property EstadoAvanceOnboarding $estado
 * @property int $intentos_count
 * @property string|null $ultima_calificacion
 * @property string|null $mejor_calificacion
 * @property Carbon|null $aprobado_en
 * @property string|null $retroalimentacion
 * @property int|null $retroalimentado_por
 * @property Carbon|null $retroalimentado_en
 * @property Carbon|null $updated_at
 * @property-read OnboardingProceso $proceso
 * @property-read OnboardingModulo $modulo
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OnboardingIntento> $intentos
 */
class OnboardingAvance extends Model
{
    protected $table = 'onboarding_avances';

    protected $fillable = [
        'onboarding_proceso_id', 'onboarding_modulo_id', 'tipo', 'orden', 'estado', 'intentos_count',
        'ultima_calificacion', 'mejor_calificacion', 'aprobado_en', 'retroalimentacion',
        'retroalimentado_por', 'retroalimentado_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoModuloOnboarding::class,
            'estado' => EstadoAvanceOnboarding::class,
            'intentos_count' => 'integer',
            'orden' => 'integer',
            'ultima_calificacion' => 'decimal:2',
            'mejor_calificacion' => 'decimal:2',
            'aprobado_en' => 'datetime',
            'retroalimentado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OnboardingProceso, $this>
     */
    public function proceso(): BelongsTo
    {
        return $this->belongsTo(OnboardingProceso::class, 'onboarding_proceso_id');
    }

    /**
     * @return BelongsTo<OnboardingModulo, $this>
     */
    public function modulo(): BelongsTo
    {
        return $this->belongsTo(OnboardingModulo::class, 'onboarding_modulo_id');
    }

    /**
     * @return HasMany<OnboardingIntento, $this>
     */
    public function intentos(): HasMany
    {
        return $this->hasMany(OnboardingIntento::class)->orderBy('numero');
    }
}
