<?php

namespace App\Models;

use App\Enums\MotivoCobertura;
use Database\Factories\CoberturaPuestoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cobertura temporal: un colaborador actúa en un puesto de otra sucursal o
 * región sin perder el suyo (ver migración y CoberturaPuestoService).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $puesto_id
 * @property int|null $sucursal_id
 * @property int|null $region_id
 * @property MotivoCobertura $motivo
 * @property string|null $nota
 * @property Carbon $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property bool $activa
 * @property int|null $registrada_por
 * @property int|null $finalizada_por
 */
class CoberturaPuesto extends Model
{
    /** @use HasFactory<CoberturaPuestoFactory> */
    use HasFactory;

    protected $table = 'coberturas_puesto';

    protected $fillable = [
        'colaborador_id',
        'puesto_id',
        'sucursal_id',
        'region_id',
        'motivo',
        'nota',
        'fecha_inicio',
        'fecha_fin',
        'activa',
        'registrada_por',
        'finalizada_por',
    ];

    protected function casts(): array
    {
        return [
            'motivo' => MotivoCobertura::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    /**
     * @return BelongsTo<NodoComercial, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(NodoComercial::class, 'region_id');
    }
}
