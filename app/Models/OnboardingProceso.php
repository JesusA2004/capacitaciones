<?php

namespace App\Models;

use App\Enums\EstadoOnboarding;
use App\Enums\TipoProcesoOnboarding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Onboarding (Etapa 3) de un colaborador: nace cuando los contratos de la
 * contratación (o del reingreso) quedan firmados. Ver
 * App\Services\Onboarding\OnboardingService. Con tipo «reinduccion» lo abre
 * RH al volver a asignar la Inducción Mr. Lana a alguien sin onboarding de
 * ingreso abierto (AsignacionInduccionService): nunca activa al colaborador
 * ni toca su contratación ni su ciclo laboral.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $contrato_laboral_id
 * @property int|null $reingreso_id
 * @property TipoProcesoOnboarding $tipo
 * @property EstadoOnboarding $estado
 * @property Carbon $iniciado_en
 * @property Carbon|null $completado_en
 * @property int|null $completado_por
 * @property int|null $colaborador_abierto_id
 * @property Carbon|null $created_at
 * @property-read Colaborador $colaborador
 * @property-read Collection<int, OnboardingAvance> $avances
 * @property-read Collection<int, EntregaActivo> $entregas
 */
class OnboardingProceso extends Model
{
    protected $table = 'onboarding_procesos';

    protected $fillable = [
        'colaborador_id', 'contrato_laboral_id', 'reingreso_id', 'tipo', 'estado', 'iniciado_en', 'completado_en',
        'completado_por', 'colaborador_abierto_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tipo' => 'ingreso',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoOnboarding::class,
            'tipo' => TipoProcesoOnboarding::class,
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

    public function esReinduccion(): bool
    {
        return $this->tipo === TipoProcesoOnboarding::Reinduccion;
    }

    /**
     * Solo los onboardings de INGRESO (excluye reinducciones): los que
     * cuentan para la etapa del ciclo laboral, la contratación y el tablero.
     *
     * @param  Builder<OnboardingProceso>  $query
     * @return Builder<OnboardingProceso>
     */
    public function scopeDeIngreso(Builder $query): Builder
    {
        return $query->where('tipo', TipoProcesoOnboarding::Ingreso->value);
    }
}
