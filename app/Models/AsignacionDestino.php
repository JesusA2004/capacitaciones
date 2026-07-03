<?php

namespace App\Models;

use App\Enums\TipoDestinoAsignacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $asignacion_id
 * @property TipoDestinoAsignacion $tipo_destino
 * @property int|null $destino_id
 */
class AsignacionDestino extends Model
{
    protected $fillable = ['asignacion_id', 'tipo_destino', 'destino_id'];

    protected function casts(): array
    {
        return [
            'tipo_destino' => TipoDestinoAsignacion::class,
        ];
    }

    /**
     * @return BelongsTo<Asignacion, $this>
     */
    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class);
    }
}
