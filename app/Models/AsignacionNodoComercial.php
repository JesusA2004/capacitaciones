<?php

namespace App\Models;

use App\Enums\TipoAsignacionNodoComercial;
use Database\Factories\AsignacionNodoComercialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fila de historial de cobertura de un App\Models\NodoComercial (ver
 * docs/HEADCOUNT_Y_VACANTES.md, sección "Matriz comercial"): quién cubrió
 * qué ruta, con qué rol (gestor/apoyo/volante) y en qué periodo. Gestionada
 * siempre a través de App\Services\MatrizComercial\MatrizComercialService,
 * nunca creada/cerrada directamente desde un controlador.
 *
 * @property int $id
 * @property int $user_id
 * @property int $nodo_comercial_id
 * @property TipoAsignacionNodoComercial $tipo_asignacion
 * @property bool $activo
 * @property Carbon $fecha_inicio
 * @property Carbon|null $fecha_fin
 */
class AsignacionNodoComercial extends Model
{
    /** @use HasFactory<AsignacionNodoComercialFactory> */
    use HasFactory;

    protected $table = 'user_nodo_comercial';

    protected $fillable = [
        'user_id',
        'nodo_comercial_id',
        'tipo_asignacion',
        'activo',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected function casts(): array
    {
        return [
            'tipo_asignacion' => TipoAsignacionNodoComercial::class,
            'activo' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NodoComercial, $this>
     */
    public function nodo(): BelongsTo
    {
        return $this->belongsTo(NodoComercial::class, 'nodo_comercial_id');
    }
}
