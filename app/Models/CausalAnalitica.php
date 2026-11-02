<?php

namespace App\Models;

use App\Enums\ClasificacionSalida;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Causal HOMOLOGADA para análisis de rotación (p. ej. «Mejor oferta laboral o
 * salarial»). Agrupa motivos de distintas fuentes para la estadística; no es la
 * causal administrativa del flujo de Bajas (App\Models\CausalRotacion), que se
 * mapea a una de estas con causales_rotacion.causal_analitica_id. Una causal con
 * registros nunca se borra: se desactiva.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property ClasificacionSalida $clasificacion
 * @property bool $es_estructural Reestructura, cierre o fin de contrato: se separa en indicadores complementarios, nunca se excluye del total.
 * @property string|null $descripcion
 * @property int $orden
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CausalAnalitica extends Model
{
    protected $table = 'causales_analiticas';

    /** Clave de la categoría para bajas sin causal registrada. */
    public const SIN_CAUSAL = 'sin_causal';

    protected $fillable = ['clave', 'nombre', 'clasificacion', 'es_estructural', 'descripcion', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'clasificacion' => ClasificacionSalida::class,
            'es_estructural' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @param  Builder<CausalAnalitica>  $query
     * @return Builder<CausalAnalitica>
     */
    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('nombre');
    }

    /**
     * @return HasMany<CausalRotacion, $this>
     */
    public function causalesRotacion(): HasMany
    {
        return $this->hasMany(CausalRotacion::class, 'causal_analitica_id');
    }
}
