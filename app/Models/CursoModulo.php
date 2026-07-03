<?php

namespace App\Models;

use Database\Factories\CursoModuloFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $curso_id
 * @property string $titulo
 * @property string|null $descripcion
 * @property int $orden
 */
class CursoModulo extends Model
{
    /** @use HasFactory<CursoModuloFactory> */
    use HasFactory;

    protected $fillable = ['curso_id', 'titulo', 'descripcion', 'orden'];

    /**
     * @return BelongsTo<Curso, $this>
     */
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    /**
     * @return HasMany<Leccion, $this>
     */
    public function lecciones(): HasMany
    {
        return $this->hasMany(Leccion::class)->orderBy('orden');
    }
}
