<?php

namespace App\Models;

use App\Enums\EstadoAsignacion;
use Database\Factories\AsignacionUsuarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $asignacion_id
 * @property int $user_id
 * @property EstadoAsignacion $estado
 * @property Carbon|null $fecha_limite
 * @property Carbon|null $completado_en
 * @property Carbon|null $recordatorio_enviado_en
 */
class AsignacionUsuario extends Model
{
    /** @use HasFactory<AsignacionUsuarioFactory> */
    use HasFactory;

    protected $table = 'asignaciones_usuario';

    protected $fillable = ['asignacion_id', 'user_id', 'estado', 'fecha_limite', 'completado_en', 'recordatorio_enviado_en'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAsignacion::class,
            'fecha_limite' => 'datetime',
            'completado_en' => 'datetime',
            'recordatorio_enviado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asignacion, $this>
     */
    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
