<?php

namespace App\Models;

use App\Enums\ResultadoEtapaCandidato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Estudio socioeconómico (visita, video, PDF). Cargar NO es decidir: el
 * resultado queda nulo («pendiente de revisión») hasta que el responsable
 * del candidato lo abre y decide viable / no viable (revisado_por/en). El
 * checklist es informativo: el sistema nunca automatiza la decisión.
 *
 * @property int $id
 * @property int $candidato_id
 * @property Carbon $fecha_visita
 * @property int|null $visitador_user_id
 * @property string $direccion
 * @property array<string, mixed>|null $checklist
 * @property string|null $riesgos
 * @property string|null $observaciones
 * @property ResultadoEtapaCandidato|null $resultado
 * @property int|null $registrado_por
 * @property int|null $revisado_por
 * @property Carbon|null $revisado_en
 * @property string|null $revision_observaciones
 * @property Carbon|null $created_at
 * @property-read User|null $visitador
 */
class CandidatoSocioeconomico extends Model
{
    protected $table = 'candidato_socioeconomicos';

    protected $fillable = [
        'candidato_id', 'fecha_visita', 'visitador_user_id', 'direccion', 'checklist', 'riesgos',
        'observaciones', 'resultado', 'registrado_por', 'revisado_por', 'revisado_en', 'revision_observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_visita' => 'date',
            'checklist' => 'array',
            'resultado' => ResultadoEtapaCandidato::class,
            'revisado_en' => 'datetime',
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
