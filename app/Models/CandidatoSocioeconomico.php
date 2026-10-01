<?php

namespace App\Models;

use App\Enums\ResultadoEtapaCandidato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Estudio socioeconómico (visita del gerente de sucursal). El checklist es
 * informativo: el sistema registra la evaluación del responsable, nunca la
 * automatiza.
 *
 * @property int $id
 * @property int $candidato_id
 * @property Carbon $fecha_visita
 * @property int|null $visitador_user_id
 * @property string $direccion
 * @property array<string, mixed>|null $checklist
 * @property string|null $riesgos
 * @property string|null $observaciones
 * @property ResultadoEtapaCandidato $resultado
 * @property int|null $registrado_por
 * @property Carbon|null $created_at
 * @property-read User|null $visitador
 */
class CandidatoSocioeconomico extends Model
{
    protected $table = 'candidato_socioeconomicos';

    protected $fillable = [
        'candidato_id', 'fecha_visita', 'visitador_user_id', 'direccion', 'checklist', 'riesgos',
        'observaciones', 'resultado', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_visita' => 'date',
            'checklist' => 'array',
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
    public function visitador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitador_user_id');
    }

    /**
     * @return MorphMany<CandidatoEvidencia, $this>
     */
    public function evidencias(): MorphMany
    {
        return $this->morphMany(CandidatoEvidencia::class, 'evidenciable');
    }
}
