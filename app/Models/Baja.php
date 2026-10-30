<?php

namespace App\Models;

use App\Enums\EntregaAvisoRescision;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\OrigenBaja;
use App\Enums\Recontratable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Expediente de una baja (módulo de Bajas y Causales, docs/BAJAS_Y_CAUSALES.md):
 * forma de salida + causal (lista cerrada), datos copiados al momento de la
 * baja (nombre, sucursal, puesto, ingreso), documentos adjuntos y bitácora.
 *
 * Convive con el cierre laboral (CierreLaboral, finiquito y documentos
 * generados): la baja se liga a su cierre (cierre_laboral_id) en cuanto se
 * envía a RH. Solo las bajas Cerradas con es_rotacion = true cuentan en el
 * tablero de rotación.
 *
 * @property int $id
 * @property string $folio BAJ-AAAA-NNNN
 * @property int|null $colaborador_id
 * @property int|null $cierre_laboral_id
 * @property string $nombre_colaborador
 * @property string|null $numero_empleado
 * @property int|null $sucursal_id
 * @property int|null $puesto_id
 * @property int|null $departamento_id
 * @property Carbon|null $fecha_ingreso
 * @property Carbon|null $fecha_baja Último día laborado.
 * @property int|null $antiguedad_dias
 * @property FormaSalida|null $forma_salida
 * @property int|null $causal_rotacion_id
 * @property string|null $comentarios
 * @property Recontratable|null $recontratable
 * @property Carbon|null $fecha_conocimiento_falta
 * @property EntregaAvisoRescision|null $entrega_aviso_rescision
 * @property Carbon|null $fecha_entrega_aviso
 * @property bool $es_rotacion
 * @property OrigenBaja $origen
 * @property string|null $motivo_original
 * @property string|null $causal_migrada_de
 * @property EstatusBaja $estatus
 * @property int|null $creado_por
 * @property int|null $solicitado_por
 * @property Carbon|null $enviado_en
 * @property int|null $validado_por
 * @property Carbon|null $validado_en
 * @property int|null $juridico_por
 * @property Carbon|null $juridico_en
 * @property int|null $rechazado_por
 * @property Carbon|null $rechazado_en
 * @property string|null $rechazado_area rh | juridico
 * @property string|null $motivo_rechazo
 * @property int|null $cerrado_por
 * @property Carbon|null $cerrado_en
 * @property int|null $cancelado_por
 * @property Carbon|null $cancelado_en
 * @property string|null $motivo_cancelacion
 * @property Carbon|null $acceso_suspendido_en
 * @property array<string, string>|null $alertas Alertas ya enviadas (clave => fecha).
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 * @property-read CausalRotacion|null $causal
 * @property-read BajaAvisoRescision|null $aviso
 * @property-read CierreLaboral|null $cierre
 * @property-read Sucursal|null $sucursal
 * @property-read Puesto|null $puesto
 */
class Baja extends Model
{
    protected $table = 'bajas';

    protected $fillable = [
        'folio', 'colaborador_id', 'cierre_laboral_id', 'nombre_colaborador', 'numero_empleado', 'sucursal_id', 'puesto_id',
        'departamento_id', 'fecha_ingreso', 'fecha_baja', 'antiguedad_dias', 'forma_salida', 'causal_rotacion_id', 'comentarios',
        'recontratable', 'fecha_conocimiento_falta', 'entrega_aviso_rescision', 'fecha_entrega_aviso', 'es_rotacion', 'origen',
        'motivo_original', 'causal_migrada_de', 'estatus', 'creado_por', 'solicitado_por', 'enviado_en', 'validado_por',
        'validado_en', 'juridico_por', 'juridico_en', 'rechazado_por', 'rechazado_en', 'rechazado_area', 'motivo_rechazo',
        'cerrado_por', 'cerrado_en', 'cancelado_por', 'cancelado_en', 'motivo_cancelacion', 'acceso_suspendido_en', 'alertas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_baja' => 'date',
            'antiguedad_dias' => 'integer',
            'forma_salida' => FormaSalida::class,
            'recontratable' => Recontratable::class,
            'fecha_conocimiento_falta' => 'date',
            'entrega_aviso_rescision' => EntregaAvisoRescision::class,
            'fecha_entrega_aviso' => 'date',
            'es_rotacion' => 'boolean',
            'origen' => OrigenBaja::class,
            'estatus' => EstatusBaja::class,
            'enviado_en' => 'datetime',
            'validado_en' => 'datetime',
            'juridico_en' => 'datetime',
            'rechazado_en' => 'datetime',
            'cerrado_en' => 'datetime',
            'cancelado_en' => 'datetime',
            'acceso_suspendido_en' => 'datetime',
            'alertas' => 'array',
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
     * @return BelongsTo<CausalRotacion, $this>
     */
    public function causal(): BelongsTo
    {
        return $this->belongsTo(CausalRotacion::class, 'causal_rotacion_id');
    }

    /**
     * @return BelongsTo<CierreLaboral, $this>
     */
    public function cierre(): BelongsTo
    {
        return $this->belongsTo(CierreLaboral::class, 'cierre_laboral_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<BajaDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(BajaDocumento::class);
    }

    /**
     * @return HasMany<BajaBitacora, $this>
     */
    public function bitacora(): HasMany
    {
        return $this->hasMany(BajaBitacora::class);
    }

    /**
     * @return HasOne<BajaAvisoRescision, $this>
     */
    public function aviso(): HasOne
    {
        return $this->hasOne(BajaAvisoRescision::class);
    }

    public function requiereJuridico(): bool
    {
        return $this->causal !== null && $this->causal->requiere_juridico;
    }
}
