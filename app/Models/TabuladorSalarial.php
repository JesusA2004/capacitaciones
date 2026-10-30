<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una versión del rango de sueldo mensual de un puesto. Solo se agregan versiones:
 * la vigente es la que no tiene vigente_hasta. No es el sueldo real de nadie.
 *
 * @property int $id
 * @property int $puesto_id
 * @property int $version
 * @property string $minimo
 * @property string $medio
 * @property string $maximo
 * @property string $fuente
 * @property Carbon $fecha_actualizacion
 * @property Carbon $vigente_desde
 * @property Carbon|null $vigente_hasta
 * @property string $origen
 */
class TabuladorSalarial extends Model
{
    protected $table = 'tabuladores_salariales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'minimo' => 'decimal:2',
            'medio' => 'decimal:2',
            'maximo' => 'decimal:2',
            'fecha_actualizacion' => 'date',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /** @return BelongsTo<Puesto, $this> */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
