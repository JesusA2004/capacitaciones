<?php

namespace App\Models;

use App\Enums\ResultadoEtapaCandidato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrevista del gerente de sucursal con el candidato (Etapa 1).
 *
 * @property int $id
 * @property int $candidato_id
 * @property Carbon $realizada_en
 * @property int|null $entrevistador_user_id
 * @property string|null $observaciones
 * @property ResultadoEtapaCandidato $resultado
 * @property int|null $registrado_por
 * @property Carbon|null $created_at
 * @property-read User|null $entrevistador
 */
class CandidatoEntrevista extends Model
{
    protected $table = 'candidato_entrevistas';

    protected $fillable = ['candidato_id', 'realizada_en', 'entrevistador_user_id', 'observaciones', 'resultado', 'registrado_por'];

    protected function casts(): array
    {
        return [
            'realizada_en' => 'datetime',
            'resultado' => ResultadoEtapaCandidato::class,
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
    public function entrevistador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entrevistador_user_id');
    }
}
