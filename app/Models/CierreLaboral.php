<?php

namespace App\Models;

use App\Enums\EstadoCierreLaboral;
use App\Enums\TipoBaja;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Proceso de cierre laboral de un colaborador (no renovación, renuncia u
 * otro motivo de App\Enums\TipoBaja). Reutiliza la solicitud interna de
 * baja existente (solicitud_interna_id) — y con ella el cálculo de finiquito
 * (FiniquitoCalculo) y la baja real (BajaColaboradorService) — en vez de
 * duplicar ese flujo. Ver App\Services\CierreLaboral\CierreLaboralService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $solicitud_interna_id
 * @property int|null $evaluacion_id
 * @property TipoBaja $tipo_baja
 * @property string $motivo
 * @property Carbon $fecha_efectiva
 * @property EstadoCierreLaboral $estado
 * @property Carbon|null $aviso_registrado_en
 * @property int|null $aviso_documento_id
 * @property Carbon|null $pago_confirmado_en
 * @property int|null $pago_confirmado_por
 * @property string|null $referencia_pago
 * @property Carbon|null $baja_ejecutada_en
 * @property Carbon|null $expediente_cerrado_en
 * @property int|null $iniciado_por
 * @property string|null $observaciones
 * @property Carbon|null $autorizado_rh_en
 * @property int|null $autorizado_rh_por
 * @property Carbon|null $rechazado_en
 * @property string|null $motivo_rechazo
 * @property Carbon|null $finiquito_autorizado_en
 * @property int|null $finiquito_autorizado_por
 * @property Carbon|null $pago_programado_para
 * @property string|null $pago_monto
 * @property string|null $pago_metodo
 * @property int|null $pago_responsable_user_id
 * @property string|null $pago_observaciones
 * @property int|null $pago_programado_por
 * @property Carbon|null $pago_programado_en
 * @property Carbon|null $cita_firma_en
 * @property Carbon|null $acceso_suspendido_en
 * @property int|null $cita_registrada_por
 * @property Carbon|null $created_at
 * @property-read Colaborador $colaborador
 * @property-read SolicitudInterna|null $solicitud
 */
class CierreLaboral extends Model
{
    use LogsActivity;

    protected $table = 'cierres_laborales';

    protected $fillable = [
        'colaborador_id', 'solicitud_interna_id', 'evaluacion_id', 'tipo_baja', 'motivo',
        'fecha_efectiva', 'estado', 'aviso_registrado_en', 'aviso_documento_id',
        'pago_confirmado_en', 'pago_confirmado_por', 'referencia_pago',
        'baja_ejecutada_en', 'expediente_cerrado_en', 'iniciado_por', 'observaciones',
        'autorizado_rh_en', 'autorizado_rh_por', 'rechazado_en', 'motivo_rechazo',
        'finiquito_autorizado_en', 'finiquito_autorizado_por', 'pago_programado_para', 'pago_monto',
        'pago_metodo', 'pago_responsable_user_id', 'pago_observaciones', 'pago_programado_por',
        'pago_programado_en', 'cita_firma_en', 'cita_registrada_por', 'acceso_suspendido_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo_baja' => TipoBaja::class,
            'estado' => EstadoCierreLaboral::class,
            'fecha_efectiva' => 'date',
            'aviso_registrado_en' => 'datetime',
            'pago_confirmado_en' => 'datetime',
            'baja_ejecutada_en' => 'datetime',
            'expediente_cerrado_en' => 'datetime',
            'autorizado_rh_en' => 'datetime',
            'rechazado_en' => 'datetime',
            'finiquito_autorizado_en' => 'datetime',
            'pago_programado_para' => 'date',
            'pago_monto' => 'decimal:2',
            'pago_programado_en' => 'datetime',
            'cita_firma_en' => 'datetime',
            'acceso_suspendido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pagoResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pago_responsable_user_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * @return BelongsTo<EvaluacionPeriodoPrueba, $this>
     */
    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(EvaluacionPeriodoPrueba::class, 'evaluacion_id');
    }

    /**
     * @return BelongsTo<SolicitudInternaDocumento, $this>
     */
    public function avisoDocumento(): BelongsTo
    {
        return $this->belongsTo(SolicitudInternaDocumento::class, 'aviso_documento_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'tipo_baja', 'fecha_efectiva', 'pago_confirmado_en', 'referencia_pago', 'baja_ejecutada_en', 'expediente_cerrado_en'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
