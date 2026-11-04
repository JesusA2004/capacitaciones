<?php

namespace App\Models;

use App\Enums\EstadoAvisoAltaSistemas;
use App\Enums\OrigenAvisoAltaSistemas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marca persistente del correo «Alta para asignar usuarios» a Sistemas: uno
 * por contratación (ver la migración create_avisos_alta_sistemas_table).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property OrigenAvisoAltaSistemas $origen_tipo
 * @property int $origen_id
 * @property EstadoAvisoAltaSistemas $estado
 * @property int $intentos
 * @property Carbon|null $enviado_en
 * @property string|null $ultimo_error
 * @property string|null $destinatarios
 * @property int|null $solicitado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AvisoAltaSistemas extends Model
{
    protected $table = 'avisos_alta_sistemas';

    protected $fillable = [
        'colaborador_id', 'origen_tipo', 'origen_id', 'estado', 'intentos',
        'enviado_en', 'ultimo_error', 'destinatarios', 'solicitado_por',
    ];

    protected $attributes = [
        'estado' => 'pendiente',
        'intentos' => 0,
    ];

    protected function casts(): array
    {
        return [
            'origen_tipo' => OrigenAvisoAltaSistemas::class,
            'estado' => EstadoAvisoAltaSistemas::class,
            'intentos' => 'integer',
            'enviado_en' => 'datetime',
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
