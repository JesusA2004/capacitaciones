<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia personal (Estudio socioeconómico: «Referencias Personales», 4
 * renglones). No es una referencia laboral (esas las valida RH en
 * candidato_referencias).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $orden
 * @property string $nombre
 * @property string $relacion
 * @property string $telefono
 * @property int|null $anios_conocerlo
 */
class ColaboradorReferenciaPersonal extends Model
{
    protected $table = 'colaborador_referencias_personales';

    protected $fillable = ['colaborador_id', 'orden', 'nombre', 'relacion', 'telefono', 'anios_conocerlo'];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'anios_conocerlo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
