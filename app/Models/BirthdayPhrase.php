<?php

namespace App\Models;

use Database\Factories\BirthdayPhraseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catalogo de frases usadas por App\Services\Cumpleanos\CumpleanosService al
 * generar una BirthdayGreeting. Rotan para no repetir siempre la misma (ver
 * elegirFrase() en el service).
 *
 * @property int $id
 * @property string $texto
 * @property string|null $categoria
 * @property bool $activo
 * @property int|null $orden
 * @property int $usado_count
 * @property Carbon|null $ultimo_uso_at
 */
class BirthdayPhrase extends Model
{
    /** @use HasFactory<BirthdayPhraseFactory> */
    use HasFactory;

    protected $fillable = [
        'texto', 'categoria', 'activo', 'orden', 'usado_count', 'ultimo_uso_at',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
            'usado_count' => 'integer',
            'ultimo_uso_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<BirthdayPhrase>  $query
     * @return Builder<BirthdayPhrase>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
