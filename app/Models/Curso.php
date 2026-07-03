<?php

namespace App\Models;

use App\Enums\EstadoCurso;
use Database\Factories\CursoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $titulo
 * @property string|null $descripcion
 * @property string|null $objetivo
 * @property string|null $imagen_portada_path
 * @property int|null $duracion_estimada_minutos
 * @property EstadoCurso $estado
 * @property Carbon|null $disponible_desde
 * @property Carbon|null $disponible_hasta
 * @property int|null $calificacion_minima
 * @property int|null $intentos_maximos
 * @property bool $requiere_orden
 * @property bool $genera_constancia
 * @property bool $alcance_global
 * @property array<int, string>|null $etiquetas
 * @property int|null $responsable_id
 * @property Carbon|null $publicado_en
 */
class Curso extends Model
{
    /** @use HasFactory<CursoFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'titulo', 'descripcion', 'objetivo', 'imagen_portada_path', 'duracion_estimada_minutos',
        'estado', 'disponible_desde', 'disponible_hasta', 'calificacion_minima', 'intentos_maximos',
        'requiere_orden', 'genera_constancia', 'alcance_global', 'etiquetas', 'responsable_id',
        'publicado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCurso::class,
            'disponible_desde' => 'datetime',
            'disponible_hasta' => 'datetime',
            'requiere_orden' => 'boolean',
            'genera_constancia' => 'boolean',
            'alcance_global' => 'boolean',
            'etiquetas' => 'array',
            'publicado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * @return HasMany<CursoModulo, $this>
     */
    public function modulos(): HasMany
    {
        return $this->hasMany(CursoModulo::class)->orderBy('orden');
    }

    /**
     * @return MorphMany<Asignacion, $this>
     */
    public function asignaciones(): MorphMany
    {
        return $this->morphMany(Asignacion::class, 'asignable');
    }

    /**
     * Cursos que deben completarse antes de poder inscribirse a este.
     *
     * @return BelongsToMany<Curso, $this>
     */
    public function requisitosPrevios(): BelongsToMany
    {
        return $this->belongsToMany(Curso::class, 'curso_requisito_previo', 'curso_id', 'requisito_curso_id');
    }

    /**
     * @return HasMany<InscripcionCurso, $this>
     */
    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionCurso::class);
    }

    public function estaPublicado(): bool
    {
        return $this->estado === EstadoCurso::Publicado;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['titulo', 'estado', 'responsable_id', 'publicado_en'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
