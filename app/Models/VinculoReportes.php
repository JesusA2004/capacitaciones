<?php

namespace App\Models;

use App\Enums\MetodoVinculoReportes;
use App\Enums\TipoVinculoReportes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vínculo auditable PEOPLE ↔ Reportes (Seguimiento, docs/SEGUIMIENTO.md).
 * Nunca se borra: al cambiarlo se desactiva y se crea otro.
 *
 * @property int $id
 * @property TipoVinculoReportes $tipo
 * @property int|null $sucursal_id
 * @property int|null $colaborador_id
 * @property int $reportes_id Id del branch (sucursal) o del employee (colaborador) en Reportes.
 * @property string|null $reportes_clave
 * @property string $reportes_nombre
 * @property MetodoVinculoReportes $metodo
 * @property bool $activo
 * @property int|null $vinculado_por
 * @property int|null $desactivado_por
 * @property Carbon|null $desactivado_en
 * @property string|null $motivo_desactivacion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal|null $sucursal
 * @property-read Colaborador|null $colaborador
 * @property-read User|null $vinculadoPor
 */
class VinculoReportes extends Model
{
    protected $table = 'vinculos_reportes';

    protected $fillable = [
        'tipo', 'sucursal_id', 'colaborador_id', 'reportes_id', 'reportes_clave',
        'reportes_nombre', 'metodo', 'activo', 'vinculado_por', 'desactivado_por',
        'desactivado_en', 'motivo_desactivacion',
    ];

    protected $attributes = [
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoVinculoReportes::class,
            'metodo' => MetodoVinculoReportes::class,
            'activo' => 'boolean',
            'reportes_id' => 'integer',
            'desactivado_en' => 'datetime',
        ];
    }

    /**
     * @param  Builder<VinculoReportes>  $query
     * @return Builder<VinculoReportes>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @param  Builder<VinculoReportes>  $query
     * @return Builder<VinculoReportes>
     */
    public function scopeDeTipo(Builder $query, TipoVinculoReportes $tipo): Builder
    {
        return $query->where('tipo', $tipo->value);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function vinculadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vinculado_por');
    }
}
