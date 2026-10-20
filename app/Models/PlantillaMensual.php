<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Plantilla de una sucursal en un mes (denominador del índice de rotación):
 * activos el día 1 y el último día; plantilla_promedio = (inicio + cierre)
 * / 2. Foto automática (comando bajas:foto-plantilla en el scheduler), sin
 * captura manual. `fuente`: foto (tomada el día) o reconstruida (backfill
 * desde fechas de ingreso/baja de los colaboradores). Ver
 * App\Services\Bajas\PlantillaMensualService.
 *
 * @property int $id
 * @property string $periodo AAAA-MM
 * @property int $anio
 * @property int $mes
 * @property int $sucursal_id
 * @property int|null $activos_inicio
 * @property int|null $activos_cierre
 * @property string|null $plantilla_promedio
 * @property string $fuente
 * @property Carbon|null $foto_inicio_en
 * @property Carbon|null $foto_cierre_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal $sucursal
 */
class PlantillaMensual extends Model
{
    public const FUENTE_FOTO = 'foto';

    public const FUENTE_RECONSTRUIDA = 'reconstruida';

    protected $table = 'plantilla_mensual';

    protected $fillable = ['periodo', 'anio', 'mes', 'sucursal_id', 'activos_inicio', 'activos_cierre', 'plantilla_promedio', 'fuente', 'foto_inicio_en', 'foto_cierre_en'];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'activos_inicio' => 'integer',
            'activos_cierre' => 'integer',
            'plantilla_promedio' => 'decimal:2',
            'foto_inicio_en' => 'datetime',
            'foto_cierre_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
