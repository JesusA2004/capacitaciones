<?php

namespace App\Models;

use Database\Factories\OpcionPreguntaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pregunta_id
 * @property string $texto
 * @property bool $es_correcta
 * @property int $orden
 */
class OpcionPregunta extends Model
{
    /** @use HasFactory<OpcionPreguntaFactory> */
    use HasFactory;

    protected $table = 'opciones_pregunta';

    protected $fillable = ['pregunta_id', 'texto', 'es_correcta', 'orden'];

    protected function casts(): array
    {
        return ['es_correcta' => 'boolean'];
    }

    /**
     * @return BelongsTo<Pregunta, $this>
     */
    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(Pregunta::class);
    }
}
