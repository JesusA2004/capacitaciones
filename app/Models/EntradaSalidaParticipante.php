<?php

namespace App\Models;

use Database\Factories\EntradaSalidaParticipanteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una entrada/salida real de un SesionParticipante. Varias filas para el
 * mismo participante = varias reconexiones.
 *
 * @property int $id
 * @property int $sesion_participante_id
 * @property Carbon $inicio
 * @property Carbon|null $fin
 * @property int|null $duracion_segundos
 * @property string $origen
 * @property string|null $identificador_externo
 */
class EntradaSalidaParticipante extends Model
{
    /** @use HasFactory<EntradaSalidaParticipanteFactory> */
    use HasFactory;

    protected $table = 'entradas_salidas_participante';

    protected $fillable = [
        'sesion_participante_id', 'inicio', 'fin', 'duracion_segundos', 'origen', 'identificador_externo',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SesionParticipante, $this>
     */
    public function sesionParticipante(): BelongsTo
    {
        return $this->belongsTo(SesionParticipante::class);
    }
}
