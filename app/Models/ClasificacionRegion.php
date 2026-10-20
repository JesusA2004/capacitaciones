<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lado (Región Q1 / Región Q3 de la matriz comercial) al que RH clasificó a un
 * Gerente de Sucursal. Ver ClasificacionRegionService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $region_id
 * @property int|null $clasificada_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClasificacionRegion extends Model
{
    protected $table = 'clasificaciones_region';

    protected $fillable = [
        'colaborador_id',
        'region_id',
        'clasificada_por',
    ];

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * @return BelongsTo<NodoComercial, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(NodoComercial::class, 'region_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function clasificadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clasificada_por');
    }
}
