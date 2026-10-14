<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de un candidato a una pregunta de filtro al registrarlo
 * (App\Models\PreguntaFiltroCandidato). Guarda copia del texto para que el
 * historial no cambie si RH edita la pregunta después.
 *
 * @property int $id
 * @property int $candidato_id
 * @property int|null $pregunta_filtro_candidato_id
 * @property string $pregunta
 * @property bool $respuesta
 * @property bool $cumple
 * @property bool $eliminatoria
 */
class CandidatoRespuestaFiltro extends Model
{
    protected $table = 'candidato_respuestas_filtro';

    protected $fillable = ['candidato_id', 'pregunta_filtro_candidato_id', 'pregunta', 'respuesta', 'cumple', 'eliminatoria'];

    protected function casts(): array
    {
        return [
            'respuesta' => 'boolean',
            'cumple' => 'boolean',
            'eliminatoria' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }
}
