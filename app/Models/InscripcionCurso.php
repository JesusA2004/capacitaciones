<?php

namespace App\Models;

use App\Enums\EstadoProgreso;
use Database\Factories\InscripcionCursoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $curso_id
 * @property int|null $asignacion_usuario_id
 * @property EstadoProgreso $estado
 * @property Carbon|null $iniciado_en
 * @property Carbon|null $completado_en
 * @property int|null $calificacion_final
 */
class InscripcionCurso extends Model
{
    /** @use HasFactory<InscripcionCursoFactory> */
    use HasFactory;

    protected $table = 'inscripciones_curso';

    protected $fillable = [
        'user_id', 'curso_id', 'asignacion_usuario_id', 'estado',
        'iniciado_en', 'completado_en', 'calificacion_final',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoProgreso::class,
            'iniciado_en' => 'datetime',
            'completado_en' => 'datetime',
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
     * @return BelongsTo<Curso, $this>
     */
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    /**
     * @return BelongsTo<AsignacionUsuario, $this>
     */
    public function asignacionUsuario(): BelongsTo
    {
        return $this->belongsTo(AsignacionUsuario::class);
    }
}
