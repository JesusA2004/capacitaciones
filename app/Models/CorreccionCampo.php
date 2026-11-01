<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Antes y después de un campo de una corrección. Inmutable. Si es confidencial,
 * los valores van cifrados con Crypt (ver CorreccionService).
 *
 * @property int $id
 * @property int $correccion_id
 * @property string $campo
 * @property string $etiqueta
 * @property string|null $valor_anterior
 * @property string|null $valor_nuevo
 * @property bool $confidencial
 */
class CorreccionCampo extends Model
{
    public $timestamps = false;

    protected $table = 'correccion_campos';

    protected $fillable = ['correccion_id', 'campo', 'etiqueta', 'valor_anterior', 'valor_nuevo', 'confidencial'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('El detalle de una corrección no se edita.'));
        static::deleting(fn (): never => throw new LogicException('El detalle de una corrección no se borra.'));
    }

    protected function casts(): array
    {
        return ['confidencial' => 'boolean'];
    }

    /**
     * @return BelongsTo<Correccion, $this>
     */
    public function correccion(): BelongsTo
    {
        return $this->belongsTo(Correccion::class);
    }
}
