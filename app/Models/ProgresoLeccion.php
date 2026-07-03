<?php

namespace App\Models;

use App\Enums\EstadoProgreso;
use Database\Factories\ProgresoLeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $leccion_id
 * @property EstadoProgreso $estado
 * @property Carbon|null $iniciado_en
 * @property Carbon|null $completado_en
 */
class ProgresoLeccion extends Model
{
    /** @use HasFactory<ProgresoLeccionFactory> */
    use HasFactory;

    protected $table = 'progreso_lecciones';

    protected $fillable = ['user_id', 'leccion_id', 'estado', 'iniciado_en', 'completado_en'];

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
     * @return BelongsTo<Leccion, $this>
     */
    public function leccion(): BelongsTo
    {
        return $this->belongsTo(Leccion::class);
    }
}
