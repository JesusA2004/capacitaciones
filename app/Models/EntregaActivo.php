<?php

namespace App\Models;

use App\Enums\EstadoEntregaActivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrega presencial de un activo al colaborador con su carta responsiva.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $onboarding_proceso_id
 * @property int $tipo_activo_id
 * @property string|null $identificador
 * @property string|null $descripcion
 * @property Carbon $entregado_en
 * @property int|null $entregado_por
 * @property int|null $generated_document_id
 * @property EstadoEntregaActivo $estado
 * @property Carbon|null $devuelto_en
 * @property string|null $observaciones
 * @property-read TipoActivo $tipo
 * @property-read GeneratedDocument|null $responsiva
 * @property-read User|null $entregadoPor
 */
class EntregaActivo extends Model
{
    protected $table = 'entregas_activo';

    protected $fillable = [
        'colaborador_id', 'onboarding_proceso_id', 'tipo_activo_id', 'identificador', 'descripcion',
        'entregado_en', 'entregado_por', 'generated_document_id', 'estado', 'devuelto_en', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoEntregaActivo::class,
            'entregado_en' => 'date',
            'devuelto_en' => 'date',
        ];
    }

    /**
     * @return BelongsTo<TipoActivo, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoActivo::class, 'tipo_activo_id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function responsiva(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
