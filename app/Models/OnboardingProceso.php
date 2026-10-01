<?php

namespace App\Models;

use App\Enums\EstadoOnboarding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Onboarding (Etapa 3) de un colaborador: nace cuando los contratos de la
 * contratación (o del reingreso) quedan firmados. Ver
 * App\Services\Onboarding\OnboardingService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $contrato_laboral_id
 * @property int|null $reingreso_id
 * @property EstadoOnboarding $estado
 * @property Carbon $iniciado_en
 * @property Carbon|null $completado_en
 * @property int|null $completado_por
 * @property int|null $colaborador_abierto_id
 * @property Carbon|null $created_at
 * @property-read Colaborador $colaborador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OnboardingAvance> $avances
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EntregaActivo> $entregas
 */
class OnboardingProceso extends Model
{
    protected $table = 'onboarding_procesos';

    protected $fillable = [
        'colaborador_id', 'contrato_laboral_id', 'reingreso_id', 'estado', 'iniciado_en', 'completado_en',
        'completado_por', 'colaborador_abierto_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoOnboarding::class,
            'iniciado_en' => 'datetime',
            'completado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ContratoLaboral, $this>
     */
    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoLaboral::class, 'contrato_laboral_id');
    }

    /**
     * @return HasMany<OnboardingAvance, $this>
     */
    public function avances(): HasMany
    {
        return $this->hasMany(OnboardingAvance::class)->orderBy('tipo')->orderBy('orden');
    }

    /**
     * @return HasMany<EntregaActivo, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaActivo::class);
    }
}
