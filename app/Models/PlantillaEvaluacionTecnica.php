<?php

namespace App\Models;

use App\Enums\ClaveFormularioEvaluacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Formulario técnico reutilizable (p. ej. «Técnica de cobranza — Gestor»).
 * Sus versiones viven en formularios_evaluacion (clave «tecnica», familia
 * «tecnica_{id}») y se editan con el mismo constructor tipo Google Forms.
 * Un paso del calendario por puesto (PasoEvaluacionTecnica) la usa; varias
 * pruebas pueden compartir la misma plantilla.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $activa
 * @property int|null $creado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $pasos_count Solo con withCount('pasos').
 */
class PlantillaEvaluacionTecnica extends Model
{
    protected $table = 'plantillas_evaluacion_tecnica';

    protected $fillable = ['nombre', 'descripcion', 'activa', 'creado_por'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    /**
     * @return HasMany<FormularioEvaluacion, $this>
     */
    public function versiones(): HasMany
    {
        return $this->hasMany(FormularioEvaluacion::class, 'plantilla_evaluacion_tecnica_id')
            ->where('clave', ClaveFormularioEvaluacion::Tecnica->value);
    }

    /**
     * @return HasMany<PasoEvaluacionTecnica, $this>
     */
    public function pasos(): HasMany
    {
        return $this->hasMany(PasoEvaluacionTecnica::class, 'plantilla_evaluacion_tecnica_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function familia(): string
    {
        return FormularioEvaluacion::familiaDe(ClaveFormularioEvaluacion::Tecnica, $this->id);
    }
}
