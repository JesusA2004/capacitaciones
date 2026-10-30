<?php

namespace App\Models;

use App\Enums\FormaSalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Subcatálogo de causales dentro de una forma de salida (p. ej. «Pensión» en
 * Otras terminaciones). Lo captura RH; nunca se borra, se desactiva, y sus
 * causales dejan de ofrecerse sin perder su historial.
 *
 * @property int $id
 * @property FormaSalida $forma_salida
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $orden
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CausalGrupo extends Model
{
    protected $table = 'causal_grupos';

    protected $fillable = ['forma_salida', 'nombre', 'descripcion', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'forma_salida' => FormaSalida::class,
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CausalRotacion, $this>
     */
    public function causales(): HasMany
    {
        return $this->hasMany(CausalRotacion::class, 'causal_grupo_id');
    }
}
