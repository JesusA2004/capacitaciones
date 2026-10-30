<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alta de un periodo anterior al sistema, confirmada por RH con una importación
 * auditable. Es un dato de reporte: nunca cambia la fecha de ingreso del
 * colaborador ni se deduce de sus bajas.
 *
 * @property int $id
 * @property int|null $importacion_excel_id
 * @property string $nombre
 * @property string $llave Evita cargar dos veces la misma alta.
 * @property string|null $numero_empleado
 * @property int|null $colaborador_id
 * @property Carbon $fecha_alta
 * @property int|null $sucursal_id
 * @property int|null $puesto_id
 * @property int|null $registrado_por
 * @property-read Sucursal|null $sucursal
 * @property-read Puesto|null $puesto
 */
class AltaHistorica extends Model
{
    protected $table = 'altas_historicas';

    protected $fillable = ['importacion_excel_id', 'nombre', 'llave', 'numero_empleado', 'colaborador_id', 'fecha_alta', 'sucursal_id', 'puesto_id', 'registrado_por'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fecha_alta' => 'date'];
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }
}
