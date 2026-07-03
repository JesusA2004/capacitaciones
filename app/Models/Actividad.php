<?php

namespace App\Models;

use App\Enums\TipoEntregaActividad;
use Database\Factories\ActividadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $leccion_id
 * @property string $titulo
 * @property string|null $instrucciones
 * @property TipoEntregaActividad $tipo_entrega
 * @property int $calificacion_minima
 * @property Carbon|null $fecha_limite
 */
class Actividad extends Model
{
    /** @use HasFactory<ActividadFactory> */
    use HasFactory;

    protected $table = 'actividades';

    protected $fillable = ['leccion_id', 'titulo', 'instrucciones', 'tipo_entrega', 'calificacion_minima', 'fecha_limite'];

    protected function casts(): array
    {
        return [
            'tipo_entrega' => TipoEntregaActividad::class,
            'fecha_limite' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Leccion, $this>
     */
    public function leccion(): BelongsTo
    {
        return $this->belongsTo(Leccion::class);
    }

    /**
     * @return HasMany<EntregaActividad, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaActividad::class);
    }
}
