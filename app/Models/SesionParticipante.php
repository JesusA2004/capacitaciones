<?php

namespace App\Models;

use App\Enums\EstadoIdentificacionParticipante;
use App\Enums\TipoParticipante;
use Database\Factories\SesionParticipanteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un participante detectado por el proveedor dentro de un RegistroSesion.
 * `user_id` solo se llena por coincidencia confiable de correo electrónico
 * (nunca por nombre). Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 4.
 *
 * @property int $id
 * @property int $registro_sesion_id
 * @property int|null $user_id
 * @property string|null $identificador_externo
 * @property string|null $correo_detectado
 * @property string|null $nombre_mostrado
 * @property TipoParticipante $tipo_participante
 * @property EstadoIdentificacionParticipante $estado_identificacion
 * @property int $minutos_acumulados
 * @property int $porcentaje_sesion
 * @property int $numero_reconexiones
 * @property string|null $resultado_calculado
 */
class SesionParticipante extends Model
{
    /** @use HasFactory<SesionParticipanteFactory> */
    use HasFactory;

    protected $table = 'sesiones_participante';

    protected $fillable = [
        'registro_sesion_id', 'user_id', 'identificador_externo', 'correo_detectado', 'nombre_mostrado',
        'tipo_participante', 'estado_identificacion', 'minutos_acumulados', 'porcentaje_sesion',
        'numero_reconexiones', 'resultado_calculado',
    ];

    protected function casts(): array
    {
        return [
            'tipo_participante' => TipoParticipante::class,
            'estado_identificacion' => EstadoIdentificacionParticipante::class,
        ];
    }

    /**
     * @return BelongsTo<RegistroSesion, $this>
     */
    public function registroSesion(): BelongsTo
    {
        return $this->belongsTo(RegistroSesion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EntradaSalidaParticipante, $this>
     */
    public function entradasSalidas(): HasMany
    {
        return $this->hasMany(EntradaSalidaParticipante::class);
    }
}
