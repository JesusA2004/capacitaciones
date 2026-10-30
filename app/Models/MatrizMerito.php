<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una versión de la matriz de mérito. Sugiere incrementos; nunca los aplica.
 *
 * @property int $id
 * @property array<mixed> $celdas
 * @property Carbon $vigente_desde
 * @property Carbon|null $vigente_hasta
 * @property string|null $motivo
 */
class MatrizMerito extends Model
{
    protected $table = 'matrices_merito';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'celdas' => 'array',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
