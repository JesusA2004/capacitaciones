<?php

namespace App\Models;

use App\Enums\QuienContestaEvaluacionTecnica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un paso del calendario de evaluaciones técnicas de un puesto: «el día N
 * desde la fecha de ingreso se aplica la evaluación X». Nombre por defecto
 * «Seguimiento {dias}» (editable).
 *
 * @property int $id
 * @property int $puesto_id
 * @property int $dias
 * @property string $nombre
 * @property int|null $plantilla_evaluacion_tecnica_id
 * @property QuienContestaEvaluacionTecnica $contesta
 * @property string $calificacion_minima
 * @property int $dias_para_contestar
 * @property bool $es_final
 * @property bool $activo
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Puesto|null $puesto
 * @property-read PlantillaEvaluacionTecnica|null $plantilla
 */
class PasoEvaluacionTecnica extends Model
{
    protected $table = 'pasos_evaluacion_tecnica';

    protected $fillable = [
        'puesto_id', 'dias', 'nombre', 'plantilla_evaluacion_tecnica_id', 'contesta',
        'calificacion_minima', 'dias_para_contestar', 'es_final', 'activo', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'dias' => 'integer',
            'contesta' => QuienContestaEvaluacionTecnica::class,
            'calificacion_minima' => 'decimal:2',
            'dias_para_contestar' => 'integer',
            'es_final' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public static function nombrePorDefecto(int $dias): string
    {
        return sprintf('Seguimiento %d', $dias);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<PlantillaEvaluacionTecnica, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEvaluacionTecnica::class, 'plantilla_evaluacion_tecnica_id');
    }

    /**
     * @return HasMany<EvaluacionTecnica, $this>
     */
    public function evaluaciones(): HasMany
    {
        return $this->hasMany(EvaluacionTecnica::class, 'paso_evaluacion_tecnica_id');
    }
}
