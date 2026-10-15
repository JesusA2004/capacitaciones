<?php

namespace App\Models;

use App\Enums\ResultadoAsistenciaInduccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un asistente de una sesión de presentación de inducción.
 *
 * @property int $id
 * @property int $sesion_induccion_id
 * @property int $colaborador_id
 * @property ResultadoAsistenciaInduccion|null $resultado
 * @property int|null $evaluacion_induccion_id
 * @property Carbon|null $procesado_en
 * @property-read SesionInduccion $sesion
 * @property-read Colaborador $colaborador
 * @property-read EvaluacionInduccion|null $evaluacion
 */
class SesionInduccionColaborador extends Model
{
    protected $table = 'sesion_induccion_colaboradores';

    protected $fillable = ['sesion_induccion_id', 'colaborador_id', 'resultado', 'evaluacion_induccion_id', 'procesado_en'];

    protected function casts(): array
    {
        return [
            'resultado' => ResultadoAsistenciaInduccion::class,
            'procesado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SesionInduccion, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionInduccion::class, 'sesion_induccion_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<EvaluacionInduccion, $this>
     */
    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(EvaluacionInduccion::class, 'evaluacion_induccion_id');
    }
}
