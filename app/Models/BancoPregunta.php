<?php

namespace App\Models;

use Database\Factories\BancoPreguntaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $creado_por
 */
class BancoPregunta extends Model
{
    /** @use HasFactory<BancoPreguntaFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'bancos_preguntas';

    protected $fillable = ['nombre', 'descripcion', 'creado_por'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<Pregunta, $this>
     */
    public function preguntas(): HasMany
    {
        return $this->hasMany(Pregunta::class);
    }
}
