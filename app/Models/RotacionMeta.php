<?php

namespace App\Models;

use App\Enums\SegmentoRotacion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta mensual de rotación de un segmento con su vigencia. Solo se agregan
 * filas: el historial conserva cada cambio y los meses pasados siguen midiéndose
 * contra la meta que tenían (App\Services\Rotacion\RotacionMetaService).
 *
 * @property int $id
 * @property SegmentoRotacion $segmento
 * @property string $verde_hasta
 * @property string $ambar_hasta
 * @property CarbonInterface $vigente_desde
 * @property string|null $motivo
 * @property int|null $registrado_por
 * @property CarbonInterface|null $created_at
 * @property-read User|null $registradoPor
 */
class RotacionMeta extends Model
{
    protected $table = 'rotacion_metas';

    protected $fillable = ['segmento', 'verde_hasta', 'ambar_hasta', 'vigente_desde', 'motivo', 'registrado_por'];

    protected function casts(): array
    {
        return [
            'segmento' => SegmentoRotacion::class,
            'verde_hasta' => 'decimal:2',
            'ambar_hasta' => 'decimal:2',
            'vigente_desde' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
