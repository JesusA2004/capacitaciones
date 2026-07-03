<?php

namespace App\Models;

use App\Enums\EstadoAsistencia;
use Database\Factories\AsistenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sesion_en_vivo_id
 * @property int $user_id
 * @property int|null $sesion_participante_id
 * @property EstadoAsistencia $estado
 * @property Carbon|null $unido_en
 * @property Carbon|null $salido_en
 * @property int|null $duracion_segundos
 * @property int|null $minutos_totales
 * @property int|null $porcentaje_sesion
 * @property int $numero_reconexiones
 * @property string|null $motivo_estado
 * @property Carbon|null $sincronizado_en
 * @property int|null $corregido_por
 * @property string|null $motivo_correccion
 * @property string|null $estado_anterior
 * @property int|null $minutos_anteriores
 * @property string|null $evidencia_correccion
 * @property string|null $correccion_ip
 * @property string|null $correccion_user_agent
 * @property string|null $correccion_origen
 */
class Asistencia extends Model
{
    /** @use HasFactory<AsistenciaFactory> */
    use HasFactory;

    protected $table = 'asistencias';

    protected $fillable = [
        'sesion_en_vivo_id', 'user_id', 'sesion_participante_id', 'estado', 'unido_en', 'salido_en',
        'duracion_segundos', 'minutos_totales', 'porcentaje_sesion', 'numero_reconexiones',
        'motivo_estado', 'sincronizado_en', 'corregido_por', 'motivo_correccion',
        'estado_anterior', 'minutos_anteriores', 'evidencia_correccion',
        'correccion_ip', 'correccion_user_agent', 'correccion_origen',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAsistencia::class,
            'unido_en' => 'datetime',
            'salido_en' => 'datetime',
            'sincronizado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SesionEnVivo, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionEnVivo::class, 'sesion_en_vivo_id');
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
    public function corregidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corregido_por');
    }

    /**
     * @return BelongsTo<SesionParticipante, $this>
     */
    public function sesionParticipante(): BelongsTo
    {
        return $this->belongsTo(SesionParticipante::class);
    }
}
