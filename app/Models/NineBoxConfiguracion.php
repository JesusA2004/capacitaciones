<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versión de la configuración de la Nine Box (pesos, metas, cortes). Cada
 * cambio inserta una versión nueva; la vigente es la más reciente. Nunca
 * se edita ni se borra (docs/NINE_BOX.md).
 *
 * @property int $id
 * @property array<string, mixed> $valores
 * @property string|null $motivo
 * @property int|null $guardada_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $guardadaPor
 */
class NineBoxConfiguracion extends Model
{
    protected $table = 'nine_box_configuraciones';

    protected $fillable = ['valores', 'motivo', 'guardada_por'];

    protected function casts(): array
    {
        return [
            'valores' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardada_por');
    }
}
