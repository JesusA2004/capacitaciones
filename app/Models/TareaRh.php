<?php

namespace App\Models;

use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Pendiente de la bandeja de trabajo. Conoce el objeto relacionado
 * (relacionado_type/relacionado_id), la acción esperada, la prioridad y su
 * ciclo leído/resuelto. El destinatario es una cuenta concreta
 * (asignado_user_id) o "quien tenga el permiso X dentro de su alcance"
 * (asignado_permiso). Ver App\Services\Tareas\TareaService.
 *
 * @property int $id
 * @property TipoTarea $tipo
 * @property string $titulo
 * @property string|null $descripcion
 * @property PrioridadTarea $prioridad
 * @property string|null $relacionado_type
 * @property int|null $relacionado_id
 * @property int|null $colaborador_id
 * @property int|null $asignado_user_id
 * @property string|null $asignado_permiso
 * @property string|null $accion
 * @property Carbon|null $vence_en
 * @property string $clave
 * @property string|null $clave_abierta
 * @property Carbon|null $read_at
 * @property Carbon|null $resuelta_en
 * @property int|null $resuelta_por
 * @property array<string, mixed>|null $datos
 * @property Carbon|null $created_at
 * @property-read Colaborador|null $colaborador
 */
class TareaRh extends Model
{
    protected $table = 'tareas_rh';

    protected $fillable = [
        'tipo', 'titulo', 'descripcion', 'prioridad', 'relacionado_type', 'relacionado_id',
        'colaborador_id', 'asignado_user_id', 'asignado_permiso', 'accion', 'vence_en',
        'clave', 'clave_abierta', 'read_at', 'resuelta_en', 'resuelta_por', 'datos',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoTarea::class,
            'prioridad' => PrioridadTarea::class,
            'vence_en' => 'date',
            'read_at' => 'datetime',
            'resuelta_en' => 'datetime',
            'datos' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function relacionado(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_user_id');
    }
}
