<?php

namespace App\Models;

use Database\Factories\ReciboNominaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recibo de nómina INFORMATIVO ya generado (ver App\Services\Nomina\ReciboNominaService).
 * No es un CFDI timbrado ante el SAT ni calcula ISR/IMSS — es un comprobante
 * interno que RH le entrega al colaborador, con snapshot de
 * percepciones/deducciones en el momento de generarse (un cambio posterior
 * al sueldo del colaborador no reescribe recibos ya emitidos).
 *
 * @property int $id
 * @property int $colaborador_id
 * @property Carbon $periodo_inicio
 * @property Carbon $periodo_fin
 * @property Carbon $fecha_pago
 * @property string $sueldo_base
 * @property array<int, array{concepto: string, monto: float}> $percepciones
 * @property array<int, array{concepto: string, monto: float, prestamo_id?: int}> $deducciones
 * @property string $total_percepciones
 * @property string $total_deducciones
 * @property string $neto
 * @property int|null $generado_por
 * @property string|null $pdf_disk
 * @property string|null $pdf_path
 */
class ReciboNomina extends Model
{
    /** @use HasFactory<ReciboNominaFactory> */
    use HasFactory;

    protected $table = 'recibos_nomina';

    protected $fillable = [
        'colaborador_id',
        'periodo_inicio',
        'periodo_fin',
        'fecha_pago',
        'sueldo_base',
        'percepciones',
        'deducciones',
        'total_percepciones',
        'total_deducciones',
        'neto',
        'generado_por',
        'pdf_disk',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
            'fecha_pago' => 'date',
            'sueldo_base' => 'decimal:2',
            'percepciones' => 'array',
            'deducciones' => 'array',
            'total_percepciones' => 'decimal:2',
            'total_deducciones' => 'decimal:2',
            'neto' => 'decimal:2',
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
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
