<?php

namespace App\Models;

use App\Enums\TipoPregunta;
use Database\Factories\PreguntaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $banco_pregunta_id
 * @property string $enunciado
 * @property TipoPregunta $tipo
 * @property int $puntos
 * @property string|null $explicacion
 */
class Pregunta extends Model
{
    /** @use HasFactory<PreguntaFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'preguntas';

    protected $fillable = ['banco_pregunta_id', 'enunciado', 'tipo', 'puntos', 'explicacion'];

    protected function casts(): array
    {
        return ['tipo' => TipoPregunta::class];
    }

    /**
     * @return BelongsTo<BancoPregunta, $this>
     */
    public function banco(): BelongsTo
    {
        return $this->belongsTo(BancoPregunta::class, 'banco_pregunta_id');
    }

    /**
     * @return HasMany<OpcionPregunta, $this>
     */
    public function opciones(): HasMany
    {
        return $this->hasMany(OpcionPregunta::class)->orderBy('orden');
    }

    /**
     * @return BelongsToMany<Cuestionario, $this>
     */
    public function cuestionarios(): BelongsToMany
    {
        return $this->belongsToMany(Cuestionario::class, 'cuestionario_pregunta')
            ->withPivot(['orden', 'puntos'])
            ->withTimestamps();
    }
}
