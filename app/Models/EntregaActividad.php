<?php

namespace App\Models;

use App\Enums\EstadoEntregaActividad;
use Database\Factories\EntregaActividadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cada reenvio (tras un rechazo) crea una fila nueva con `version`
 * incrementada; esta tabla es su propio historial, no existe una tabla
 * de historial de entregas por separado.
 *
 * @property int $id
 * @property int $actividad_id
 * @property int $user_id
 * @property int $version
 * @property string|null $contenido_texto
 * @property string|null $url
 * @property int|null $recurso_multimedia_id
 * @property EstadoEntregaActividad $estado
 * @property int|null $calificacion
 * @property string|null $retroalimentacion
 * @property Carbon $entregado_en
 * @property Carbon|null $calificado_en
 * @property int|null $calificado_por
 */
class EntregaActividad extends Model
{
    /** @use HasFactory<EntregaActividadFactory> */
    use HasFactory;

    protected $table = 'entregas_actividad';

    protected $fillable = [
        'actividad_id', 'user_id', 'version', 'contenido_texto', 'url', 'recurso_multimedia_id',
        'estado', 'calificacion', 'retroalimentacion', 'entregado_en', 'calificado_en', 'calificado_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoEntregaActividad::class,
            'entregado_en' => 'datetime',
            'calificado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Actividad, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<RecursoMultimedia, $this>
     */
    public function recursoMultimedia(): BelongsTo
    {
        return $this->belongsTo(RecursoMultimedia::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calificadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calificado_por');
    }
}
