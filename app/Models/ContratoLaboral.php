<?php

namespace App\Models;

use App\Enums\EstadoContratoLaboral;
use App\Enums\TipoContratacion;
use Database\Factories\ContratoLaboralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Relación contractual de un colaborador (periodo de prueba, capacitación,
 * tiempo determinado o indeterminado). Guarda un snapshot de sueldo/puesto/
 * sucursal al iniciar: el contrato histórico nunca cambia aunque cambien los
 * datos vigentes del colaborador. El documento PDF vive en
 * GeneratedDocument (generated_document_id). Ver
 * App\Services\Contratos\ContratoLaboralService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property TipoContratacion $tipo
 * @property Carbon $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property EstadoContratoLaboral $estado
 * @property string|null $sueldo_mensual
 * @property int|null $puesto_id
 * @property int|null $sucursal_id
 * @property int|null $generated_document_id
 * @property int|null $contrato_anterior_id
 * @property Carbon|null $aviso_vencimiento_en
 * @property int|null $creado_por
 * @property string|null $observaciones
 * @property-read Colaborador $colaborador
 * @property-read GeneratedDocument|null $documento
 * @property-read EvaluacionPeriodoPrueba|null $evaluacion
 */
class ContratoLaboral extends Model
{
    /** @use HasFactory<ContratoLaboralFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'contratos_laborales';

    protected $fillable = [
        'colaborador_id', 'tipo', 'fecha_inicio', 'fecha_fin', 'estado',
        'sueldo_mensual', 'puesto_id', 'sucursal_id', 'generated_document_id',
        'contrato_anterior_id', 'aviso_vencimiento_en', 'creado_por', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoContratacion::class,
            'estado' => EstadoContratoLaboral::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'sueldo_mensual' => 'decimal:2',
            'aviso_vencimiento_en' => 'datetime',
        ];
    }

    public function diasParaVencer(): ?int
    {
        if ($this->fecha_fin === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->fecha_fin->copy()->startOfDay(), false);
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return BelongsTo<ContratoLaboral, $this>
     */
    public function contratoAnterior(): BelongsTo
    {
        return $this->belongsTo(ContratoLaboral::class, 'contrato_anterior_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return HasOne<EvaluacionPeriodoPrueba, $this>
     */
    public function evaluacion(): HasOne
    {
        return $this->hasOne(EvaluacionPeriodoPrueba::class, 'contrato_laboral_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'fecha_inicio', 'fecha_fin', 'estado', 'sueldo_mensual', 'generated_document_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
