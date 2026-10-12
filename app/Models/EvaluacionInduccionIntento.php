<?php

namespace App\Models;

use App\Enums\NivelEvaluacionInduccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Intento de presentación de la evaluación de inducción. Las secciones A/B/C
 * se califican en el servidor al presentar (contra config/induccion_evaluacion.php,
 * que nunca se expone al cliente). La sección D (casos prácticos) queda
 * calificacion_d = null hasta que RH la captura con la rúbrica oficial —
 * calificacion_total y nivel también quedan null hasta ese momento. Se
 * conservan TODOS los intentos, nunca se sobrescribe uno anterior.
 *
 * @property int $id
 * @property int $evaluacion_induccion_id
 * @property int $numero
 * @property array<string, array<int|string, mixed>> $respuestas
 * @property array{claridad?: int, conocimiento_puesto?: int, bienvenida?: int, tema_reforzar?: string|null}|null $opinion
 * @property string $calificacion_a
 * @property string $calificacion_b
 * @property string $calificacion_c
 * @property string|null $calificacion_d
 * @property array<int, array{clave: string, puntos: int}>|null $desglose_d
 * @property string|null $calificacion_total
 * @property NivelEvaluacionInduccion|null $nivel
 * @property bool|null $aprobado
 * @property string|null $retroalimentacion_previa
 * @property int|null $presentado_por
 * @property Carbon $presentado_en
 * @property int|null $calificado_por
 * @property Carbon|null $calificado_en
 * @property-read EvaluacionInduccion $evaluacion
 */
class EvaluacionInduccionIntento extends Model
{
    protected $table = 'evaluacion_induccion_intentos';

    protected $fillable = [
        'evaluacion_induccion_id', 'numero', 'respuestas', 'opinion',
        'calificacion_a', 'calificacion_b', 'calificacion_c', 'calificacion_d', 'desglose_d',
        'calificacion_total', 'nivel', 'aprobado', 'retroalimentacion_previa',
        'presentado_por', 'presentado_en', 'calificado_por', 'calificado_en',
    ];

    protected function casts(): array
    {
        return [
            'respuestas' => 'array',
            'opinion' => 'array',
            'desglose_d' => 'array',
            'calificacion_a' => 'decimal:2',
            'calificacion_b' => 'decimal:2',
            'calificacion_c' => 'decimal:2',
            'calificacion_d' => 'decimal:2',
            'calificacion_total' => 'decimal:2',
            'nivel' => NivelEvaluacionInduccion::class,
            'aprobado' => 'boolean',
            'numero' => 'integer',
            'presentado_en' => 'datetime',
            'calificado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EvaluacionInduccion, $this>
     */
    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(EvaluacionInduccion::class, 'evaluacion_induccion_id');
    }

    public function pendienteCalificacionRh(): bool
    {
        return $this->calificacion_d === null;
    }
}
