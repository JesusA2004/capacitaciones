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
 * @property EstadoAsistencia $estado
 * @property Carbon|null $unido_en
 * @property Carbon|null $salido_en
 * @property int|null $duracion_segundos
 * @property int|null $corregido_por
 * @property string|null $motivo_correccion
 */
class Asistencia extends Model
{
    /** @use HasFactory<AsistenciaFactory> */
    use HasFactory;

    protected $table = 'asistencias';

    protected $fillable = [
        'sesion_en_vivo_id', 'user_id', 'estado', 'unido_en', 'salido_en',
        'duracion_segundos', 'corregido_por', 'motivo_correccion',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAsistencia::class,
            'unido_en' => 'datetime',
            'salido_en' => 'datetime',
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
}
