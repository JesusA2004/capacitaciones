<?php

namespace App\Models;

use App\Enums\ParentescoDependiente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dependiente económico del colaborador (Estudio socioeconómico: tabla de 6
 * renglones; Solicitud de empleo: «Dependientes económicos (nombre y edad)»).
 * La edad impresa sale de la fecha de nacimiento a la fecha del documento;
 * solo si no se conoce se usa la edad declarada.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $orden
 * @property string $nombre
 * @property string $apellido_paterno
 * @property string|null $apellido_materno
 * @property ParentescoDependiente $parentesco
 * @property string|null $parentesco_otro
 * @property Carbon|null $fecha_nacimiento
 * @property int|null $edad
 * @property string $escolaridad_ocupacion
 */
class ColaboradorDependiente extends Model
{
    protected $table = 'colaborador_dependientes';

    protected $fillable = ['colaborador_id', 'orden', 'nombre', 'apellido_paterno', 'apellido_materno', 'parentesco', 'parentesco_otro', 'fecha_nacimiento', 'edad', 'escolaridad_ocupacion'];

    protected function casts(): array
    {
        return [
            'parentesco' => ParentescoDependiente::class,
            'fecha_nacimiento' => 'date',
            'orden' => 'integer',
            'edad' => 'integer',
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
