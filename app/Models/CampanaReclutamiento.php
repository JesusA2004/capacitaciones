<?php

namespace App\Models;

use App\Enums\CanalReclutamiento;
use App\Enums\TipoCostoReclutamiento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gasto de una campaña de reclutamiento en un mes/año y canal determinados
 * (docs de referencia: App\Services\Reclutamiento\CampanaReclutamientoService).
 * empresa/sucursal/departamento/puesto son opcionales: una campaña sin
 * puesto_id es gasto general (no atribuible a una posición concreta).
 *
 * @property int $id
 * @property int $mes
 * @property int $anio
 * @property CanalReclutamiento $canal
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_id
 * @property numeric-string $monto
 * @property int|null $candidatos_generados
 * @property string|null $observaciones
 * @property int|null $created_by
 * @property string|null $nombre
 * @property int|null $vacante_id
 * @property TipoCostoReclutamiento $tipo_costo
 * @property-read Vacante|null $vacante
 */
class CampanaReclutamiento extends Model
{
    protected $table = 'campanas_reclutamiento';

    protected $fillable = [
        'nombre',
        'vacante_id',
        'tipo_costo',
        'mes',
        'anio',
        'canal',
        'empresa_id',
        'sucursal_id',
        'departamento_id',
        'puesto_id',
        'monto',
        'candidatos_generados',
        'observaciones',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mes' => 'integer',
            'anio' => 'integer',
            'canal' => CanalReclutamiento::class,
            'tipo_costo' => TipoCostoReclutamiento::class,
            'monto' => 'decimal:2',
            'candidatos_generados' => 'integer',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tipo_costo' => 'publicidad',
    ];

    /**
     * Vacante a la que se destinó el gasto (null = gasto general).
     *
     * @return BelongsTo<Vacante, $this>
     */
    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    /**
     * Candidatos que RH registró como provenientes de esta campaña.
     *
     * @return HasMany<Candidato, $this>
     */
    public function candidatos(): HasMany
    {
        return $this->hasMany(Candidato::class, 'campana_reclutamiento_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Departamento, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
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
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
