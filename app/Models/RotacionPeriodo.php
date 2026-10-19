<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un mes de rotación de una sucursal según el Excel de RH: la fuente
 * canónica del índice de rotación, que consultan
 * App\Services\Rotacion\RotacionConsultaService y el tablero de RH
 * (App\Services\Reportes\TableroRhService).
 *
 * @property int $id
 * @property int $sucursal_id
 * @property int $anio
 * @property int $mes
 * @property string $sucursal_excel
 * @property string|null $hoja_excel
 * @property int $plantilla_inicial
 * @property int $altas
 * @property int $bajas
 * @property int|null $bajas_voluntarias
 * @property int|null $bajas_involuntarias
 * @property int $plantilla_final
 * @property string|null $indice
 * @property string|null $indice_excel
 * @property int $carga_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal $sucursal
 * @property-read RotacionCarga $carga
 */
class RotacionPeriodo extends Model
{
    protected $table = 'rotacion_periodos';

    protected $fillable = [
        'sucursal_id', 'anio', 'mes', 'sucursal_excel', 'hoja_excel', 'plantilla_inicial',
        'altas', 'bajas', 'bajas_voluntarias', 'bajas_involuntarias', 'plantilla_final',
        'indice', 'indice_excel', 'carga_id',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'plantilla_inicial' => 'integer',
            'altas' => 'integer',
            'bajas' => 'integer',
            'bajas_voluntarias' => 'integer',
            'bajas_involuntarias' => 'integer',
            'plantilla_final' => 'integer',
            'indice' => 'decimal:2',
            'indice_excel' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<RotacionCarga, $this>
     */
    public function carga(): BelongsTo
    {
        return $this->belongsTo(RotacionCarga::class, 'carga_id');
    }
}
