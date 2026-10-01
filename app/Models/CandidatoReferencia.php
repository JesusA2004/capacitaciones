<?php

namespace App\Models;

use App\Enums\ResultadoReferencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Referencia laboral validada individualmente (Etapa 1).
 *
 * @property int $id
 * @property int $candidato_id
 * @property string $empresa
 * @property string $contacto
 * @property string|null $telefono
 * @property string|null $relacion_puesto
 * @property ResultadoReferencia $resultado
 * @property string|null $observaciones
 * @property Carbon $fecha_validacion
 * @property int|null $validada_por
 * @property-read User|null $validadaPor
 */
class CandidatoReferencia extends Model
{
    protected $table = 'candidato_referencias';

    protected $fillable = [
        'candidato_id', 'empresa', 'contacto', 'telefono', 'relacion_puesto', 'resultado',
        'observaciones', 'fecha_validacion', 'validada_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_validacion' => 'date',
            'resultado' => ResultadoReferencia::class,
        ];
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validada_por');
    }
}
