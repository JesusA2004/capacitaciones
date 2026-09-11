<?php

namespace App\Models;

use Database\Factories\HeadcountTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Plantilla autorizada de un (sucursal, puesto). Ver
 * docs/HEADCOUNT_Y_VACANTES.md — "plantilla actual" nunca vive aquí, se
 * calcula en vivo en App\Services\Headcount\HeadcountService.
 *
 * @property int $id
 * @property int|null $empresa_id
 * @property int $sucursal_id
 * @property int|null $departamento_id
 * @property int $puesto_id
 * @property string|null $region
 * @property int $plantilla_autorizada
 * @property string $fuente
 * @property Carbon $fecha_corte
 * @property bool $editable
 */
class HeadcountTarget extends Model
{
    /** @use HasFactory<HeadcountTargetFactory> */
    use HasFactory;

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'departamento_id',
        'puesto_id',
        'region',
        'plantilla_autorizada',
        'fuente',
        'fecha_corte',
        'editable',
        'created_by_id',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'plantilla_autorizada' => 'integer',
            'fecha_corte' => 'date',
            'editable' => 'boolean',
        ];
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
}
