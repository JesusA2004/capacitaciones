<?php

namespace App\Models;

use App\Enums\EstadoEvaluacionInduccion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Evaluación oficial del Programa de Inducción (FO-RH-EI, 30 puntos) de un
 * colaborador — una fila por (onboarding_proceso_id, clave). El historial de
 * presentaciones vive en EvaluacionInduccionIntento. Ver
 * App\Services\Onboarding\EvaluacionInduccionService.
 *
 * @property int $id
 * @property int $onboarding_proceso_id
 * @property int $colaborador_id
 * @property string $clave
 * @property string $version
 * @property int|null $formulario_evaluacion_id Versión del formulario configurable (null = formato de config/induccion_evaluacion.php).
 * @property-read FormularioEvaluacion|null $formulario
 * @property EstadoEvaluacionInduccion $estado
 * @property int $intentos_count
 * @property string|null $ultima_calificacion
 * @property string|null $mejor_calificacion
 * @property Carbon|null $aprobada_en
 * @property string|null $retroalimentacion
 * @property int|null $retroalimentado_por
 * @property Carbon|null $retroalimentado_en
 * @property Carbon|null $fecha_limite_reevaluacion
 * @property-read OnboardingProceso $proceso
 * @property-read Colaborador $colaborador
 * @property-read Collection<int, EvaluacionInduccionIntento> $intentos
 */
class EvaluacionInduccion extends Model
{
    protected $table = 'evaluaciones_induccion';

    protected $fillable = [
        'onboarding_proceso_id', 'colaborador_id', 'clave', 'version', 'formulario_evaluacion_id', 'estado',
        'intentos_count', 'ultima_calificacion', 'mejor_calificacion', 'aprobada_en',
        'retroalimentacion', 'retroalimentado_por', 'retroalimentado_en', 'fecha_limite_reevaluacion',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoEvaluacionInduccion::class,
            'intentos_count' => 'integer',
            'ultima_calificacion' => 'decimal:2',
            'mejor_calificacion' => 'decimal:2',
            'aprobada_en' => 'datetime',
            'retroalimentado_en' => 'datetime',
            'fecha_limite_reevaluacion' => 'date',
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
     * @return BelongsTo<FormularioEvaluacion, $this>
     */
    public function formulario(): BelongsTo
    {
        return $this->belongsTo(FormularioEvaluacion::class, 'formulario_evaluacion_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return HasMany<EvaluacionInduccionIntento, $this>
     */
    public function intentos(): HasMany
    {
        return $this->hasMany(EvaluacionInduccionIntento::class)->orderBy('numero');
    }
}
