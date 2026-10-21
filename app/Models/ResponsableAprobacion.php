<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Responsable de aprobación explícito (≠ jefe organizacional). Ámbito por
 * persona (colaborador_id) o por puesto (puesto_id). Ver
 * App\Services\Solicitudes\AprobacionOrganizacionalService.
 *
 * @property int $id
 * @property int|null $colaborador_id
 * @property int|null $puesto_id
 * @property int $responsable_colaborador_id
 * @property string|null $motivo
 * @property int|null $asignado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 * @property-read Puesto|null $puesto
 * @property-read Colaborador|null $responsable
 * @property-read User|null $asignadoPor
 */
class ResponsableAprobacion extends Model
{
    protected $table = 'responsables_aprobacion';

    protected $fillable = ['colaborador_id', 'puesto_id', 'responsable_colaborador_id', 'motivo', 'asignado_por'];

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'responsable_colaborador_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }
}
