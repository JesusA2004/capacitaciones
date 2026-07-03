<?php

namespace App\Models;

use App\Enums\TipoLeccion;
use Database\Factories\LeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $curso_modulo_id
 * @property string $titulo
 * @property TipoLeccion $tipo
 * @property string|null $contenido
 * @property string|null $url
 * @property bool $obligatoria
 * @property int $orden
 * @property int|null $duracion_estimada_minutos
 */
class Leccion extends Model
{
    /** @use HasFactory<LeccionFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'lecciones';

    protected $fillable = [
        'curso_modulo_id', 'titulo', 'tipo', 'contenido', 'url', 'recurso_multimedia_id',
        'obligatoria', 'orden', 'duracion_estimada_minutos',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLeccion::class,
            'obligatoria' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CursoModulo, $this>
     */
    public function modulo(): BelongsTo
    {
        return $this->belongsTo(CursoModulo::class, 'curso_modulo_id');
    }

    /**
     * @return BelongsTo<RecursoMultimedia, $this>
     */
    public function recursoMultimedia(): BelongsTo
    {
        return $this->belongsTo(RecursoMultimedia::class);
    }

    /**
     * @return HasOne<Cuestionario, $this>
     */
    public function cuestionario(): HasOne
    {
        return $this->hasOne(Cuestionario::class);
    }

    /**
     * @return HasOne<Actividad, $this>
     */
    public function actividad(): HasOne
    {
        return $this->hasOne(Actividad::class);
    }

    /**
     * @return HasOne<SesionEnVivo, $this>
     */
    public function sesionEnVivo(): HasOne
    {
        return $this->hasOne(SesionEnVivo::class);
    }

    /**
     * @return HasOneThrough<Curso, CursoModulo, $this>
     */
    public function curso(): HasOneThrough
    {
        return $this->hasOneThrough(Curso::class, CursoModulo::class, 'id', 'id', 'curso_modulo_id', 'curso_id');
    }

    /**
     * Lecciones que deben completarse antes que esta (grafo explicito,
     * ademas del orden secuencial simple que aplica cuando el curso
     * tiene requiere_orden = true).
     *
     * @return BelongsToMany<Leccion, $this>
     */
    public function requisitos(): BelongsToMany
    {
        return $this->belongsToMany(Leccion::class, 'requisitos_leccion', 'leccion_id', 'requisito_leccion_id');
    }
}
