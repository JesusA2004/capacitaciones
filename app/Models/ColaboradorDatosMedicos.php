<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condición médica y alergias del colaborador (dato sensible). Cifrado en
 * reposo y fuera de la tabla general de colaboradores: solo se expone a
 * quien tiene `expedientes.datos_medicos.ver` (nunca en listados).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property string|null $condicion_medica
 * @property string|null $alergias
 * @property string|null $fuente
 * @property int|null $actualizado_por
 */
class ColaboradorDatosMedicos extends Model
{
    protected $table = 'colaborador_datos_medicos';

    protected $fillable = ['colaborador_id', 'condicion_medica', 'alergias', 'fuente', 'actualizado_por'];

    protected $hidden = ['condicion_medica', 'alergias'];

    protected function casts(): array
    {
        return ['condicion_medica' => 'encrypted', 'alergias' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
