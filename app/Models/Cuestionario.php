<?php

namespace App\Models;

use Database\Factories\CuestionarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $leccion_id
 * @property string $titulo
 * @property string|null $instrucciones
 * @property int $calificacion_minima
 * @property int|null $intentos_maximos
 * @property int|null $tiempo_limite_minutos
 * @property bool $aleatorizar_preguntas
 * @property bool $mostrar_retroalimentacion
 */
class Cuestionario extends Model
{
    /** @use HasFactory<CuestionarioFactory> */
    use HasFactory;

    protected $table = 'cuestionarios';

    protected $fillable = [
        'leccion_id', 'titulo', 'instrucciones', 'calificacion_minima', 'intentos_maximos',
        'tiempo_limite_minutos', 'aleatorizar_preguntas', 'mostrar_retroalimentacion',
    ];

    protected function casts(): array
    {
        return [
            'aleatorizar_preguntas' => 'boolean',
            'mostrar_retroalimentacion' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Leccion, $this>
     */
    public function leccion(): BelongsTo
    {
        return $this->belongsTo(Leccion::class);
    }

    /**
     * @return BelongsToMany<Pregunta, $this>
     */
    public function preguntas(): BelongsToMany
    {
        return $this->belongsToMany(Pregunta::class, 'cuestionario_pregunta')
            ->withPivot(['orden', 'puntos'])
            ->withTimestamps()
            ->orderBy('cuestionario_pregunta.orden');
    }

    /**
     * @return HasMany<IntentoCuestionario, $this>
     */
    public function intentos(): HasMany
    {
        return $this->hasMany(IntentoCuestionario::class);
    }
}
