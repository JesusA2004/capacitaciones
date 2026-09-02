<?php

namespace App\Models;

use App\Enums\EstadoSolicitudVacaciones;
use Database\Factories\SolicitudVacacionesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $fecha_inicio
 * @property Carbon $fecha_fin
 * @property int $dias_solicitados
 * @property string|null $comentario
 * @property EstadoSolicitudVacaciones $estado
 * @property int|null $revisado_por
 * @property Carbon|null $revisado_en
 * @property string|null $motivo_rechazo
 */
class SolicitudVacaciones extends Model
{
    /** @use HasFactory<SolicitudVacacionesFactory> */
    use HasFactory;

    protected $table = 'solicitudes_vacaciones';

    protected $fillable = [
        'user_id',
        'fecha_inicio',
        'fecha_fin',
        'dias_solicitados',
        'comentario',
        'estado',
        'revisado_por',
        'revisado_en',
        'motivo_rechazo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'dias_solicitados' => 'integer',
            'estado' => EstadoSolicitudVacaciones::class,
            'revisado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
