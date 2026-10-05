<?php

namespace App\Models;

use App\Enums\EstadoCambioFoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Propuesta de foto de perfil que espera (o ya tuvo) la revisión de RH.
 * La foto oficial vive en `colaboradores.foto_path`; esta fila solo guarda
 * la propuesta y la anterior (historial), nunca el binario.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $solicitado_por_id
 * @property string $foto_path
 * @property string|null $foto_anterior_path
 * @property EstadoCambioFoto $estado
 * @property string|null $motivo_rechazo
 * @property int|null $revisado_por_id
 * @property Carbon|null $revisado_en
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Colaborador $colaborador
 * @property-read User|null $solicitadoPor
 * @property-read User|null $revisadoPor
 */
class CambioFotoPerfil extends Model
{
    protected $table = 'cambios_foto_perfil';

    protected $fillable = [
        'colaborador_id', 'solicitado_por_id', 'foto_path', 'foto_anterior_path',
        'estado', 'motivo_rechazo', 'revisado_por_id', 'revisado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCambioFoto::class,
            'revisado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Colaborador, $this> */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por_id');
    }
}
