<?php

namespace App\Models;

use App\Enums\EstadoAcuseDescriptivoPuesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Descriptivo de puesto enviado por RH a un colaborador (tras aprobar su
 * evaluación de inducción) y su acuse: «OK, enterado» + comentario. Ver
 * App\Services\Onboarding\AcuseDescriptivoPuestoService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int $descriptivo_puesto_id
 * @property int $descriptivo_version
 * @property int|null $puesto_id
 * @property int|null $evaluacion_induccion_id
 * @property int $enviado_por
 * @property Carbon $enviado_en
 * @property Carbon|null $leido_en
 * @property bool $enterado
 * @property string|null $enterado_texto
 * @property Carbon|null $enterado_en
 * @property string|null $conformidad
 * @property string|null $comentario
 * @property Carbon|null $acusado_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador $colaborador
 * @property-read DescriptivoPuesto $descriptivo
 * @property-read Puesto|null $puesto
 * @property-read User $remitente
 */
class AcuseDescriptivoPuesto extends Model
{
    protected $table = 'acuses_descriptivo_puesto';

    protected $fillable = [
        'colaborador_id', 'descriptivo_puesto_id', 'descriptivo_version', 'puesto_id', 'evaluacion_induccion_id',
        'enviado_por', 'enviado_en', 'leido_en', 'enterado', 'enterado_texto', 'enterado_en', 'comentario', 'conformidad', 'acusado_en',
    ];

    protected function casts(): array
    {
        return [
            'descriptivo_version' => 'integer',
            'enviado_en' => 'datetime',
            'leido_en' => 'datetime',
            'enterado' => 'boolean',
            'enterado_en' => 'datetime',
            'acusado_en' => 'datetime',
        ];
    }

    public function estado(): EstadoAcuseDescriptivoPuesto
    {
        return match (true) {
            $this->acusado_en !== null => EstadoAcuseDescriptivoPuesto::Enterado,
            $this->leido_en !== null => EstadoAcuseDescriptivoPuesto::Leido,
            default => EstadoAcuseDescriptivoPuesto::Enviado,
        };
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<DescriptivoPuesto, $this>
     */
    public function descriptivo(): BelongsTo
    {
        return $this->belongsTo(DescriptivoPuesto::class, 'descriptivo_puesto_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
