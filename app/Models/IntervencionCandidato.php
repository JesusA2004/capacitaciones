<?php

namespace App\Models;

use App\Enums\EstadoIntervencionCandidato;
use App\Enums\RutaIntervencionCandidato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidato_id
 * @property RutaIntervencionCandidato $ruta
 * @property int|null $rechazo_rh_por
 * @property string|null $rechazo_rh_motivo
 * @property Carbon|null $rechazo_rh_en
 * @property int $gerente_solicitante_id
 * @property string $motivo_solicitud
 * @property Carbon $solicitada_en
 * @property EstadoIntervencionCandidato $estado
 * @property int|null $aprobador_id
 * @property string|null $comentario_decision
 * @property Carbon|null $decidida_en
 */
class IntervencionCandidato extends Model
{
    protected $table = 'intervenciones_candidato';

    protected $fillable = [
        'candidato_id',
        'ruta',
        'rechazo_rh_por',
        'rechazo_rh_motivo',
        'rechazo_rh_en',
        'gerente_solicitante_id',
        'motivo_solicitud',
        'solicitada_en',
        'estado',
        'aprobador_id',
        'comentario_decision',
        'decidida_en',
    ];

    protected function casts(): array
    {
        return [
            'ruta' => RutaIntervencionCandidato::class,
            'estado' => EstadoIntervencionCandidato::class,
            'rechazo_rh_en' => 'datetime',
            'solicitada_en' => 'datetime',
            'decidida_en' => 'datetime',
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
    public function rechazoRhPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazo_rh_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function gerenteSolicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_solicitante_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobador_id');
    }
}
