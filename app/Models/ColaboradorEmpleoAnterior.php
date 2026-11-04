<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Empleo anterior declarado por la persona (Solicitud de empleo: «Últimos 3
 * empleos»). `hasta` null = empleo actual. Distinto de candidato_referencias
 * (referencias laborales que RH valida por teléfono).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $orden
 * @property string $empresa
 * @property string $puesto
 * @property Carbon $desde
 * @property Carbon|null $hasta
 * @property string $funciones
 * @property string $motivo_salida
 * @property string $telefono
 */
class ColaboradorEmpleoAnterior extends Model
{
    protected $table = 'colaborador_empleos_anteriores';

    protected $fillable = ['colaborador_id', 'orden', 'empresa', 'puesto', 'desde', 'hasta', 'funciones', 'motivo_salida', 'telefono'];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
            'orden' => 'integer',
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
