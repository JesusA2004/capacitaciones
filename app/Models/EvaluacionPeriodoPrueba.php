<?php

namespace App\Models;

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\ResultadoEvaluacion;
use Database\Factories\EvaluacionPeriodoPruebaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Evaluación del periodo de prueba de un contrato (una por contrato). La
 * crea el scheduler (contratos:revisar-vencimientos), la captura el jefe
 * inmediato y la autoriza RH/Dirección. Ver
 * App\Services\Contratos\EvaluacionPeriodoPruebaService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $contrato_laboral_id
 * @property int|null $evaluador_colaborador_id
 * @property EstadoEvaluacionPrueba $estado
 * @property Carbon|null $fecha_limite
 * @property Carbon|null $fecha_evaluacion
 * @property array<int, array{criterio: string, calificacion: float|int, comentario?: string|null}>|null $criterios
 * @property string|null $calificacion
 * @property ResultadoEvaluacion|null $resultado
 * @property bool|null $recomienda_renovar
 * @property string|null $observaciones
 * @property int|null $capturada_por
 * @property Carbon|null $capturada_en
 * @property int|null $autorizada_por
 * @property Carbon|null $autorizada_en
 * @property bool|null $decision_renovar
 * @property string|null $comentario_autorizacion
 * @property int|null $contrato_renovacion_id
 * @property-read Colaborador $colaborador
 * @property-read ContratoLaboral $contrato
 * @property-read Colaborador|null $evaluador
 */
class EvaluacionPeriodoPrueba extends Model
{
    /** @use HasFactory<EvaluacionPeriodoPruebaFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'evaluaciones_periodo_prueba';

    protected $fillable = [
        'colaborador_id', 'contrato_laboral_id', 'evaluador_colaborador_id', 'estado',
        'fecha_limite', 'fecha_evaluacion', 'criterios', 'calificacion', 'resultado',
        'recomienda_renovar', 'observaciones', 'capturada_por', 'capturada_en',
        'autorizada_por', 'autorizada_en', 'decision_renovar', 'comentario_autorizacion',
        'contrato_renovacion_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoEvaluacionPrueba::class,
            'resultado' => ResultadoEvaluacion::class,
            'fecha_limite' => 'date',
            'fecha_evaluacion' => 'date',
            'criterios' => 'array',
            'calificacion' => 'decimal:2',
            'recomienda_renovar' => 'boolean',
            'decision_renovar' => 'boolean',
            'capturada_en' => 'datetime',
            'autorizada_en' => 'datetime',
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
     * @return BelongsTo<Colaborador, $this>
     */
    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'evaluador_colaborador_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function capturadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturada_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizada_por');
    }

    /**
     * @return BelongsTo<ContratoLaboral, $this>
     */
    public function contratoRenovacion(): BelongsTo
    {
        return $this->belongsTo(ContratoLaboral::class, 'contrato_renovacion_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'resultado', 'calificacion', 'recomienda_renovar', 'decision_renovar', 'autorizada_por'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
