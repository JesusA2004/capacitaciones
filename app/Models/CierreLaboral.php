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
 * @property Carbon|null $negativa_firma_en
 * @property int|null $negativa_firma_por
 * @property list<string>|null $negativa_documentos Claves de los documentos que se intentaron entregar.
 * @property string|null $negativa_observaciones
 * @property array<string, string>|null $negativa_participantes rh_nombre, rh_cargo, jefe_nombre, jefe_cargo, lugar_acta, domicilio_acta, hora_acta.
 * @property list<array{nombre: string, cargo: string}>|null $testigos
 * @property bool $finiquito_a_disposicion
 * @property Carbon|null $notificacion_electronica_en
 * @property int|null $notificacion_electronica_por
 * @property list<string>|null $notificacion_medios
 * @property list<array{tipo: string, documento_id: int, nombre: string}>|null $evidencias
 * @property Carbon|null $baja_imss_en
 * @property Carbon|null $baja_asistencia_en
 * @property Carbon|null $accesos_cancelados_en
 * @property Carbon|null $aviso_interno_en
 * @property Carbon|null $consignacion_preventiva_en
 * @property string|null $consignacion_observaciones
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
        'negativa_firma_en', 'negativa_firma_por', 'negativa_documentos', 'negativa_observaciones', 'negativa_participantes',
        'testigos', 'finiquito_a_disposicion', 'notificacion_electronica_en', 'notificacion_electronica_por',
        'notificacion_medios', 'evidencias', 'baja_imss_en', 'baja_asistencia_en', 'accesos_cancelados_en',
        'aviso_interno_en', 'consignacion_preventiva_en', 'consignacion_observaciones',
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
            'negativa_firma_en' => 'datetime',
            'negativa_documentos' => 'array',
            'negativa_participantes' => 'array',
            'testigos' => 'array',
            'finiquito_a_disposicion' => 'boolean',
            'notificacion_electronica_en' => 'datetime',
            'notificacion_medios' => 'array',
            'evidencias' => 'array',
            'baja_imss_en' => 'datetime',
            'baja_asistencia_en' => 'datetime',
            'accesos_cancelados_en' => 'datetime',
            'aviso_interno_en' => 'datetime',
            'consignacion_preventiva_en' => 'datetime',
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
