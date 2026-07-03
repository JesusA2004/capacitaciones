<?php

namespace App\Models;

use Database\Factories\RespuestaCuestionarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $intento_cuestionario_id
 * @property int $pregunta_id
 * @property int|null $opcion_pregunta_id
 * @property array<int, int>|null $opciones_seleccionadas
 * @property string|null $respuesta_texto
 * @property bool|null $es_correcta
 * @property int|null $puntos_obtenidos
 */
class RespuestaCuestionario extends Model
{
    /** @use HasFactory<RespuestaCuestionarioFactory> */
    use HasFactory;

    protected $table = 'respuestas_cuestionario';

    protected $fillable = [
        'intento_cuestionario_id', 'pregunta_id', 'opcion_pregunta_id',
        'opciones_seleccionadas', 'respuesta_texto', 'es_correcta', 'puntos_obtenidos',
    ];

    protected function casts(): array
    {
        return [
            'opciones_seleccionadas' => 'array',
            'es_correcta' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<IntentoCuestionario, $this>
     */
    public function intento(): BelongsTo
    {
        return $this->belongsTo(IntentoCuestionario::class, 'intento_cuestionario_id');
    }

    /**
     * @return BelongsTo<Pregunta, $this>
     */
    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(Pregunta::class);
    }

    /**
     * @return BelongsTo<OpcionPregunta, $this>
     */
    public function opcionSeleccionada(): BelongsTo
    {
        return $this->belongsTo(OpcionPregunta::class, 'opcion_pregunta_id');
    }
}
