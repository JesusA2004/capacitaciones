<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lo que Recursos Humanos le ASIGNÓ a un colaborador de la Inducción Mr. Lana
 * (presentación del PDF oficial y/o material de explicación audio/video),
 * con fecha límite y mensaje opcionales. Ver
 * App\Services\Onboarding\AsignacionInduccionService.
 *
 * `vigente` es true para la asignación activa del avance y NULL para las
 * retiradas o reemplazadas (historial auditable): el índice único
 * (onboarding_avance_id, vigente) admite muchas históricas y una sola activa.
 *
 * Sin ninguna fila, el avance conserva la asignación automática del
 * onboarding de ingreso (presentación + material vigente).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $onboarding_proceso_id
 * @property int $onboarding_avance_id
 * @property bool $incluye_presentacion
 * @property bool $incluye_material
 * @property Carbon|null $fecha_limite
 * @property string|null $mensaje
 * @property bool|null $vigente
 * @property int|null $asignada_por
 * @property Carbon $asignada_en
 * @property int|null $retirada_por
 * @property Carbon|null $retirada_en
 * @property string|null $motivo_retiro
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador $colaborador
 * @property-read OnboardingProceso $proceso
 * @property-read OnboardingAvance $avance
 * @property-read User|null $asignadaPor
 */
class InduccionAsignacion extends Model
{
    protected $table = 'induccion_asignaciones';

    protected $fillable = [
        'colaborador_id', 'onboarding_proceso_id', 'onboarding_avance_id', 'incluye_presentacion',
        'incluye_material', 'fecha_limite', 'mensaje', 'vigente', 'asignada_por', 'asignada_en',
        'retirada_por', 'retirada_en', 'motivo_retiro',
    ];

    protected function casts(): array
    {
        return [
            'incluye_presentacion' => 'boolean',
            'incluye_material' => 'boolean',
            'vigente' => 'boolean',
            'fecha_limite' => 'date',
            'asignada_en' => 'datetime',
            'retirada_en' => 'datetime',
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
     * @return BelongsTo<OnboardingProceso, $this>
     */
    public function proceso(): BelongsTo
    {
        return $this->belongsTo(OnboardingProceso::class, 'onboarding_proceso_id');
    }

    /**
     * @return BelongsTo<OnboardingAvance, $this>
     */
    public function avance(): BelongsTo
    {
        return $this->belongsTo(OnboardingAvance::class, 'onboarding_avance_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function asignadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignada_por');
    }
}
