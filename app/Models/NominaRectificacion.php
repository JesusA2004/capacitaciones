<?php

namespace App\Models;

use App\Enums\EstadoRectificacionNomina;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Diferencia detectada en un recibo YA EMITIDO por una incidencia posterior.
 * El recibo no cambia hasta que RH la aplica o la descarta con su nota.
 *
 * @property int $id
 * @property int $recibo_nomina_id
 * @property int $colaborador_id
 * @property EstadoRectificacionNomina $estado
 * @property string|null $dias_pagados_antes
 * @property string $dias_pagados_despues
 * @property string $dias_incapacidad_despues
 * @property string $dias_falta_despues
 * @property string $neto_antes
 * @property string $importe_sueldo_despues
 * @property string $motivo
 * @property string|null $nota_resolucion
 * @property int|null $resuelta_por
 * @property Carbon|null $resuelta_en
 * @property-read ReciboNomina $recibo
 * @property-read Colaborador $colaborador
 */
class NominaRectificacion extends Model
{
    protected $table = 'nomina_rectificaciones';

    protected $fillable = [
        'recibo_nomina_id', 'colaborador_id', 'estado', 'dias_pagados_antes', 'dias_pagados_despues',
        'dias_incapacidad_despues', 'dias_falta_despues', 'neto_antes', 'importe_sueldo_despues', 'motivo',
        'nota_resolucion', 'resuelta_por', 'resuelta_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoRectificacionNomina::class,
            'dias_pagados_antes' => 'decimal:2',
            'dias_pagados_despues' => 'decimal:2',
            'dias_incapacidad_despues' => 'decimal:2',
            'dias_falta_despues' => 'decimal:2',
            'neto_antes' => 'decimal:2',
            'importe_sueldo_despues' => 'decimal:2',
            'resuelta_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReciboNomina, $this>
     */
    public function recibo(): BelongsTo
    {
        return $this->belongsTo(ReciboNomina::class, 'recibo_nomina_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
