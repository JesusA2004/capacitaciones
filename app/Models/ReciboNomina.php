<?php

namespace App\Models;

use App\Enums\EstadoReciboNomina;
use Database\Factories\ReciboNominaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Recibo de nómina ya generado (ver App\Services\Nomina\ReciboNominaService).
 * No se timbra ni calcula ISR/IMSS — es el comprobante que RH le entrega al
 * colaborador, con snapshot de
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
 * @property EstadoReciboNomina $estado
 * @property Carbon|null $emitido_at
 * @property int|null $generado_por
 * @property string|null $pdf_disk
 * @property string|null $pdf_path
 * @property string|null $folio
 * @property string|null $tipo_periodo
 * @property int|null $ejercicio
 * @property int|null $numero_periodo
 * @property string|null $observaciones
 * @property string|null $lote_importacion
 * @property string|null $checksum
 * @property-read Colaborador $colaborador
 */
class ReciboNomina extends Model
{
    /** @use HasFactory<ReciboNominaFactory> */
    use HasFactory;

    protected $table = 'recibos_nomina';

    /** @var array<string, mixed> */
    protected $attributes = ['estado' => 'emitido'];

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
        'estado',
        'emitido_at',
        'generado_por',
        'pdf_disk',
        'pdf_path',
        'folio',
        'tipo_periodo',
        'ejercicio',
        'numero_periodo',
        'observaciones',
        'lote_importacion',
        'checksum',
    ];

    /**
     * El disco/ruta del PDF nunca se exponen: la descarga pasa por un
     * endpoint autorizado (ReciboNominaPolicy).
     *
     * @var list<string>
     */
    protected $hidden = ['pdf_disk', 'pdf_path'];

    /**
     * Detalle de conceptos (percepciones y deducciones).
     *
     * @return HasMany<ReciboNominaConcepto, $this>
     */
    public function conceptos(): HasMany
    {
        return $this->hasMany(ReciboNominaConcepto::class, 'recibo_nomina_id')->orderBy('orden')->orderBy('id');
    }

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
            'estado' => EstadoReciboNomina::class,
            'emitido_at' => 'datetime',
            'ejercicio' => 'integer',
            'numero_periodo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
