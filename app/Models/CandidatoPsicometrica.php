<?php

namespace App\Models;

use App\Enums\ResultadoEtapaCandidato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Pruebas psicométricas: Reclutamiento envía el link, registra resultados y
 * el gerente revisa si están en perfil (Etapa 1).
 *
 * @property int $id
 * @property int $candidato_id
 * @property string|null $link
 * @property Carbon|null $enviada_en
 * @property int|null $enviada_por
 * @property Carbon|null $resultados_en
 * @property int|null $resultados_por
 * @property string|null $resumen_resultados
 * @property ResultadoEtapaCandidato|null $revision_resultado
 * @property string|null $revision_observaciones
 * @property int|null $revisada_por
 * @property Carbon|null $revisada_en
 */
class CandidatoPsicometrica extends Model
{
    protected $table = 'candidato_psicometricas';

    protected $fillable = [
        'candidato_id', 'link', 'enviada_en', 'enviada_por', 'resultados_en', 'resultados_por',
        'resumen_resultados', 'revision_resultado', 'revision_observaciones', 'revisada_por', 'revisada_en',
    ];

    protected function casts(): array
    {
        return [
            'enviada_en' => 'datetime',
            'resultados_en' => 'datetime',
            'revisada_en' => 'datetime',
            'revision_resultado' => ResultadoEtapaCandidato::class,
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
     * @return MorphMany<CandidatoEvidencia, $this>
     */
    public function evidencias(): MorphMany
    {
        return $this->morphMany(CandidatoEvidencia::class, 'evidenciable');
    }
}
