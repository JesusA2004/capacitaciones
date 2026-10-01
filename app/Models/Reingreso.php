<?php

namespace App\Models;

use App\Enums\EstadoReingreso;
use App\Enums\TipoContratacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de reingreso de una persona que ya trabajó en la empresa. Nunca
 * crea otro colaborador: al autorizarse reactiva el mismo y pide solo los
 * documentos vencidos/faltantes. Ver App\Services\CicloLaboral\ReingresoService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $cierre_anterior_id
 * @property EstadoReingreso $estado
 * @property string $motivo
 * @property int|null $puesto_id
 * @property int|null $sucursal_id
 * @property int|null $jefe_id
 * @property TipoContratacion|null $tipo_contratacion
 * @property string|null $sueldo_mensual
 * @property Carbon|null $fecha_reingreso
 * @property list<int>|null $documentos_requeridos
 * @property string|null $comentario_decision
 * @property int|null $solicitado_por
 * @property int|null $decidido_por
 * @property Carbon|null $decidido_en
 * @property int|null $contrato_laboral_id
 * @property Carbon|null $completado_en
 * @property int|null $colaborador_abierto_id
 * @property Carbon|null $created_at
 * @property-read Colaborador $colaborador
 * @property-read CierreLaboral|null $cierreAnterior
 * @property-read Puesto|null $puesto
 * @property-read Sucursal|null $sucursal
 */
class Reingreso extends Model
{
    protected $table = 'reingresos';

    protected $fillable = [
        'colaborador_id', 'cierre_anterior_id', 'estado', 'motivo', 'puesto_id', 'sucursal_id', 'jefe_id',
        'tipo_contratacion', 'sueldo_mensual', 'fecha_reingreso', 'documentos_requeridos', 'comentario_decision',
        'solicitado_por', 'decidido_por', 'decidido_en', 'contrato_laboral_id', 'completado_en', 'colaborador_abierto_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoReingreso::class,
            'tipo_contratacion' => TipoContratacion::class,
            'fecha_reingreso' => 'date',
            'documentos_requeridos' => 'array',
            'decidido_en' => 'datetime',
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
     * @return BelongsTo<CierreLaboral, $this>
     */
    public function cierreAnterior(): BelongsTo
    {
        return $this->belongsTo(CierreLaboral::class, 'cierre_anterior_id');
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
     * @return BelongsTo<User, $this>
     */
    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    /**
     * @return BelongsTo<ContratoLaboral, $this>
     */
    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoLaboral::class, 'contrato_laboral_id');
    }
}
