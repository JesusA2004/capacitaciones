<?php

namespace App\Models;

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoExcepcionDireccionGeneral;
use App\Enums\ResultadoEvaluacion;
use Database\Factories\EvaluacionPeriodoPruebaFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property array<int, array{criterio: string, calificacion: float|int, comentario?: string|null, acredita?: bool}>|array<int, array{clave: string, etiqueta: string, calificacion: int}>|null $criterios
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
 * @property int|null $version null/1 = formato libre legacy; 2 = formato oficial FO-RH-EPP-001.
 * @property array<int, array{clave: string, etiqueta: string, respuesta: bool}>|null $filtros Solo version >= 2.
 * @property int|null $formulario_evaluacion_id Versión del formulario configurable (null = formato de config/contratos.php).
 * @property-read FormularioEvaluacion|null $formulario
 * @property-read Colaborador $colaborador
 * @property-read ContratoLaboral $contrato
 * @property-read Colaborador|null $evaluador
 * @property-read Collection<int, SolicitudExcepcionPeriodoPrueba> $solicitudesExcepcion
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
        'contrato_renovacion_id', 'version', 'filtros', 'formulario_evaluacion_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoEvaluacionPrueba::class,
            'resultado' => ResultadoEvaluacion::class,
            'fecha_limite' => 'date',
            'fecha_evaluacion' => 'date',
            'criterios' => 'array',
            'filtros' => 'array',
            'calificacion' => 'decimal:2',
            'recomienda_renovar' => 'boolean',
            'decision_renovar' => 'boolean',
            'capturada_en' => 'datetime',
            'autorizada_en' => 'datetime',
        ];
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
        return $this->belongsTo(Colaborador::class, 'evaluador_colaborador_id')->withTrashed();
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

    /**
     * @return HasMany<SolicitudExcepcionPeriodoPrueba, $this>
     */
    public function solicitudesExcepcion(): HasMany
    {
        return $this->hasMany(SolicitudExcepcionPeriodoPrueba::class, 'evaluacion_periodo_prueba_id');
    }

    /**
     * Excepción vigente de Dirección General para esta evaluación (si la
     * hay): habilita a RH a renovar aunque la regla canónica no se cumpla.
     */
    public function excepcionAprobada(): ?SolicitudExcepcionPeriodoPrueba
    {
        return $this->solicitudesExcepcion->firstWhere('estado', EstadoExcepcionDireccionGeneral::Aprobada);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'resultado', 'calificacion', 'recomienda_renovar', 'decision_renovar', 'autorizada_por'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
