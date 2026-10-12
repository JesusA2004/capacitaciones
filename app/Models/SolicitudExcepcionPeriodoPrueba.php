<?php

namespace App\Models;

use App\Enums\EstadoExcepcionDireccionGeneral;
use Database\Factories\SolicitudExcepcionPeriodoPruebaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de excepción a la regla canónica de renovación del periodo de
 * prueba (FO-RH-EPP-001, nota al pie). Solo Dirección General resuelve —
 * nunca un botón "forzar aprobado" en la pantalla de RH. Ver
 * App\Services\Contratos\SolicitudExcepcionPeriodoPruebaService.
 *
 * @property int $id
 * @property int $evaluacion_periodo_prueba_id
 * @property string $motivo
 * @property string|null $documento_soporte_path
 * @property int $solicitante_id
 * @property EstadoExcepcionDireccionGeneral $estado
 * @property int|null $resuelto_por
 * @property Carbon|null $resuelto_en
 * @property string|null $comentario_resolucion
 * @property-read EvaluacionPeriodoPrueba $evaluacion
 * @property-read User $solicitante
 * @property-read User|null $resolutor
 */
class SolicitudExcepcionPeriodoPrueba extends Model
{
    /** @use HasFactory<SolicitudExcepcionPeriodoPruebaFactory> */
    use HasFactory;

    protected $table = 'solicitudes_excepcion_periodo_prueba';

    protected $fillable = [
        'evaluacion_periodo_prueba_id', 'motivo', 'documento_soporte_path', 'solicitante_id',
        'estado', 'resuelto_por', 'resuelto_en', 'comentario_resolucion',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoExcepcionDireccionGeneral::class,
            'resuelto_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EvaluacionPeriodoPrueba, $this>
     */
    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(EvaluacionPeriodoPrueba::class, 'evaluacion_periodo_prueba_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
