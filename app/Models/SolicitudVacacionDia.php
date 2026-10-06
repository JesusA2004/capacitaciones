<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un día de vacaciones elegido en una solicitud (único por solicitud).
 * Ver App\Services\Solicitudes\FechasSolicitudService.
 *
 * @property int $id
 * @property int $solicitud_interna_id
 * @property int|null $colaborador_id
 * @property CarbonImmutable $fecha
 * @property-read SolicitudInterna $solicitud
 */
class SolicitudVacacionDia extends Model
{
    protected $table = 'solicitud_vacaciones_dias';

    protected $fillable = ['solicitud_interna_id', 'colaborador_id', 'fecha'];

    /**
     * Siempre «AAAA-MM-DD» en la base (sin hora): así la búsqueda por día
     * y el índice único por solicitud funcionan igual en MariaDB y SQLite.
     *
     * @return Attribute<CarbonImmutable, mixed>
     */
    protected function fecha(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $valor) => CarbonImmutable::parse((string) $valor)->startOfDay(),
            set: fn (mixed $valor) => CarbonImmutable::parse($valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : (string) $valor)->toDateString(),
        );
    }

    /** @return BelongsTo<SolicitudInterna, $this> */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }
}
