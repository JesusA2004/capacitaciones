<?php

namespace App\Models;

use Database\Factories\SesionReproduccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una sesion de reproduccion de video: se crea cada vez que el reproductor
 * se monta y sirve como ancla para los heartbeats de esa ejecucion (posicion
 * actual reportada por el cliente). El avance real y unico visto se calcula
 * a partir de IntervaloVideoVisto, no de esta tabla, para que el progreso
 * sobreviva a que el usuario cierre y reabra el reproductor en otro momento.
 *
 * @property int $id
 * @property int $user_id
 * @property int $leccion_id
 * @property int $recurso_multimedia_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $iniciada_en
 * @property Carbon|null $ultimo_heartbeat_en
 * @property int $ultima_posicion_segundos
 * @property Carbon|null $finalizada_en
 * @property bool $completada
 */
class SesionReproduccion extends Model
{
    /** @use HasFactory<SesionReproduccionFactory> */
    use HasFactory;

    protected $table = 'sesiones_reproduccion';

    protected $fillable = [
        'user_id', 'leccion_id', 'recurso_multimedia_id', 'ip_address', 'user_agent',
        'iniciada_en', 'ultimo_heartbeat_en', 'ultima_posicion_segundos', 'finalizada_en', 'completada',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'ultimo_heartbeat_en' => 'datetime',
            'finalizada_en' => 'datetime',
            'completada' => 'boolean',
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
     * @return BelongsTo<Leccion, $this>
     */
    public function leccion(): BelongsTo
    {
        return $this->belongsTo(Leccion::class, 'leccion_id');
    }

    /**
     * @return BelongsTo<RecursoMultimedia, $this>
     */
    public function recursoMultimedia(): BelongsTo
    {
        return $this->belongsTo(RecursoMultimedia::class);
    }
}
