<?php

namespace App\Models;

use App\Enums\TipoBaja;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Causal de rotación: desglose («motivo») de un tipo de baja (renuncia,
 * rescisión, fin de contrato). RH la administra desde Bajas y finiquitos →
 * «Causales de rotación». Nunca se borra si ya se usó: se desactiva.
 *
 * @property int $id
 * @property TipoBaja $tipo
 * @property string|null $clave
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $excluye_indemnizacion
 * @property bool $activo
 * @property int $orden
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CausalRotacion extends Model
{
    protected $table = 'causales_rotacion';

    protected $fillable = ['tipo', 'clave', 'nombre', 'descripcion', 'excluye_indemnizacion', 'activo', 'orden'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoBaja::class,
            'excluye_indemnizacion' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @param  Builder<CausalRotacion>  $query
     */
    public function scopeActivas(Builder $query): void
    {
        $query->where('activo', true);
    }

    /**
     * @param  Builder<CausalRotacion>  $query
     */
    public function scopeOrdenadas(Builder $query): void
    {
        $query->orderBy('orden')->orderBy('nombre');
    }

    /**
     * @return HasMany<CierreLaboral, $this>
     */
    public function cierres(): HasMany
    {
        return $this->hasMany(CierreLaboral::class, 'causal_rotacion_id');
    }

    /**
     * @return HasMany<SolicitudInterna, $this>
     */
    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudInterna::class, 'causal_rotacion_id');
    }

    /**
     * Ya la usa alguna baja (cierre o solicitud): no se puede borrar.
     */
    public function enUso(): bool
    {
        return $this->cierres()->exists() || $this->solicitudes()->exists();
    }
}
