<?php

namespace App\Models;

use App\Enums\TipoSeguimientoCandidato;
use Database\Factories\SeguimientoCandidatoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidato_id
 * @property TipoSeguimientoCandidato $tipo
 * @property string|null $nota
 * @property string|null $estado_anterior
 * @property string|null $estado_nuevo
 * @property Carbon $fecha
 * @property int|null $registrado_por
 */
class SeguimientoCandidato extends Model
{
    /** @use HasFactory<SeguimientoCandidatoFactory> */
    use HasFactory;

    protected $table = 'seguimientos_candidato';

    protected $fillable = [
        'candidato_id',
        'tipo',
        'nota',
        'estado_anterior',
        'estado_nuevo',
        'fecha',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoSeguimientoCandidato::class,
            'fecha' => 'datetime',
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
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
