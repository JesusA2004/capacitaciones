<?php

namespace App\Models;

use App\Enums\EstadoEvaluacionTecnica;
use App\Enums\QuienContestaEvaluacionTecnica;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evaluación técnica generada a un colaborador por el calendario de su
 * puesto («Seguimiento 15», «Seguimiento 30»…). Fija la versión del
 * formulario al generarse: publicar otra versión nunca cambia una ya
 * programada. Nunca se borra: si el colaborador se va, se cancela.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $puesto_id
 * @property int|null $paso_evaluacion_tecnica_id
 * @property int|null $plantilla_evaluacion_tecnica_id
 * @property int|null $formulario_evaluacion_id
 * @property string $nombre
 * @property int $dias
 * @property bool $es_final
 * @property Carbon $fecha_programada
 * @property Carbon $fecha_limite
 * @property QuienContestaEvaluacionTecnica $contesta
 * @property int|null $evaluador_colaborador_id
 * @property string $calificacion_minima
 * @property EstadoEvaluacionTecnica $estado
 * @property array<string, mixed>|null $respuestas
 * @property string|null $puntos_obtenidos
 * @property string|null $puntos_totales
 * @property string|null $calificacion
 * @property bool|null $aprobada
 * @property string|null $comentarios
 * @property Carbon|null $contestada_en
 * @property int|null $contestada_por
 * @property Carbon|null $cancelada_en
 * @property string|null $motivo_cancelacion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 * @property-read Colaborador|null $evaluador
 * @property-read Puesto|null $puesto
 * @property-read PasoEvaluacionTecnica|null $paso
 * @property-read FormularioEvaluacion|null $formulario
 * @property-read User|null $contestadaPor
 */
class EvaluacionTecnica extends Model
{
    protected $table = 'evaluaciones_tecnicas';

    protected $fillable = [
        'colaborador_id', 'puesto_id', 'paso_evaluacion_tecnica_id', 'plantilla_evaluacion_tecnica_id',
        'formulario_evaluacion_id', 'nombre', 'dias', 'es_final', 'fecha_programada', 'fecha_limite',
        'contesta', 'evaluador_colaborador_id', 'calificacion_minima', 'estado', 'respuestas',
        'puntos_obtenidos', 'puntos_totales', 'calificacion', 'aprobada', 'comentarios',
        'contestada_en', 'contestada_por', 'cancelada_en', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'dias' => 'integer',
            'es_final' => 'boolean',
            'fecha_programada' => 'date',
            'fecha_limite' => 'date',
            'contesta' => QuienContestaEvaluacionTecnica::class,
            'calificacion_minima' => 'decimal:2',
            'estado' => EstadoEvaluacionTecnica::class,
            'respuestas' => 'array',
            'puntos_obtenidos' => 'decimal:2',
            'puntos_totales' => 'decimal:2',
            'calificacion' => 'decimal:2',
            'aprobada' => 'boolean',
            'contestada_en' => 'datetime',
            'cancelada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'evaluador_colaborador_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<PasoEvaluacionTecnica, $this>
     */
    public function paso(): BelongsTo
    {
        return $this->belongsTo(PasoEvaluacionTecnica::class, 'paso_evaluacion_tecnica_id');
    }

    /**
     * @return BelongsTo<FormularioEvaluacion, $this>
     */
    public function formulario(): BelongsTo
    {
        return $this->belongsTo(FormularioEvaluacion::class, 'formulario_evaluacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contestadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contestada_por');
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoEvaluacionTecnica::Pendiente;
    }

    public function estaVencida(?CarbonInterface $hoy = null): bool
    {
        $referencia = ($hoy ?? today())->copy()->startOfDay();

        return $this->estaPendiente() && $this->fecha_limite->lt($referencia);
    }
}
