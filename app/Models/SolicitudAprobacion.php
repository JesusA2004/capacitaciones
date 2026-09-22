<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Decisión de un nivel jerárquico sobre una solicitud interna (p. ej. visto
 * bueno del jefe inmediato de un préstamo antes de la autorización de RH).
 * Una sola decisión por nivel (índice único). Ver
 * App\Services\Solicitudes\AprobacionJerarquicaService.
 *
 * @property int $id
 * @property int $solicitud_interna_id
 * @property string $nivel
 * @property string $decision
 * @property int|null $user_id
 * @property string|null $comentario
 * @property Carbon|null $created_at
 */
class SolicitudAprobacion extends Model
{
    public const NIVEL_JEFE_INMEDIATO = 'jefe_inmediato';

    public const DECISION_APROBADO = 'aprobado';

    public const DECISION_RECHAZADO = 'rechazado';

    public const UPDATED_AT = null;

    protected $table = 'solicitud_aprobaciones';

    protected $fillable = ['solicitud_interna_id', 'nivel', 'decision', 'user_id', 'comentario'];

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
