<?php

namespace App\Models;

use App\Enums\EstadoAvisoBajaSistemas;
use App\Enums\EventoAvisoBajaSistemas;
use App\Enums\OrigenAvisoBajaSistemas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Marca persistente de un correo de baja a Sistemas: uno por baja y por
 * evento (ver la migración create_avisos_baja_sistemas_table).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property OrigenAvisoBajaSistemas $origen_tipo
 * @property int $origen_id
 * @property EventoAvisoBajaSistemas $evento
 * @property EstadoAvisoBajaSistemas $estado
 * @property int $intentos
 * @property Carbon|null $enviado_en
 * @property string|null $ultimo_error
 * @property string|null $destinatarios
 * @property int|null $solicitado_por
 * @property Carbon|null $atendido_en Sistemas ya dio de baja los usuarios de la persona en las demás plataformas.
 * @property int|null $atendido_por
 * @property string|null $notas_atencion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 * @property-read User|null $atendidoPor
 */
class AvisoBajaSistemas extends Model
{
    protected $table = 'avisos_baja_sistemas';

    protected $fillable = [
        'colaborador_id', 'origen_tipo', 'origen_id', 'evento', 'estado', 'intentos',
        'enviado_en', 'ultimo_error', 'destinatarios', 'solicitado_por',
        'atendido_en', 'atendido_por', 'notas_atencion',
    ];

    protected $attributes = [
        'estado' => 'pendiente',
        'intentos' => 0,
    ];

    protected function casts(): array
    {
        return [
            'origen_tipo' => OrigenAvisoBajaSistemas::class,
            'evento' => EventoAvisoBajaSistemas::class,
            'estado' => EstadoAvisoBajaSistemas::class,
            'intentos' => 'integer',
            'enviado_en' => 'datetime',
            'atendido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function atendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendido_por');
    }
}
