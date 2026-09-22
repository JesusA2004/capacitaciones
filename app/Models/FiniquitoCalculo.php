<?php

namespace App\Models;

use App\Enums\EstadoFiniquito;
use Database\Factories\FiniquitoCalculoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cálculo de finiquito de una baja de colaborador (ver
 * App\Services\Finiquitos\FiniquitoService). Una fila por
 * SolicitudInterna de tipo baja_colaborador.
 *
 * @property int $id
 * @property int $solicitud_interna_id
 * @property int $colaborador_id
 * @property int $calculado_por_id
 * @property int|null $revisado_por_id
 * @property Carbon $fecha_calculo
 * @property Carbon $fecha_ingreso
 * @property Carbon $fecha_baja
 * @property string $sueldo_mensual
 * @property string $sueldo_diario
 * @property int $antiguedad_anios
 * @property int $antiguedad_meses
 * @property int $dias_trabajados_periodo
 * @property int $vacaciones_pendientes
 * @property string $prima_vacacional
 * @property string $aguinaldo_proporcional
 * @property string $sueldo_pendiente
 * @property string $indemnizacion
 * @property string $bonos_extra
 * @property string $descuentos
 * @property string $adeudos
 * @property array<string, mixed>|null $otros_conceptos
 * @property string $total_calculado
 * @property string $total_ajustado
 * @property string|null $comentarios_ajuste
 * @property array<string, mixed>|null $snapshot
 * @property EstadoFiniquito $estado
 * @property string|null $documento_generado_path
 * @property string|null $documento_firmado_path
 * @property string $total_percepciones
 * @property string $total_deducciones
 * @property string $neto
 * @property Carbon|null $pagado_en
 * @property int|null $pago_confirmado_por
 * @property string|null $referencia_pago
 * @property int|null $generated_document_id
 */
class FiniquitoCalculo extends Model
{
    /** @use HasFactory<FiniquitoCalculoFactory> */
    use HasFactory;

    protected $table = 'finiquito_calculos';

    protected $fillable = [
        'solicitud_interna_id',
        'colaborador_id',
        'calculado_por_id',
        'revisado_por_id',
        'fecha_calculo',
        'fecha_ingreso',
        'fecha_baja',
        'sueldo_mensual',
        'sueldo_diario',
        'antiguedad_anios',
        'antiguedad_meses',
        'dias_trabajados_periodo',
        'vacaciones_pendientes',
        'prima_vacacional',
        'aguinaldo_proporcional',
        'sueldo_pendiente',
        'indemnizacion',
        'bonos_extra',
        'descuentos',
        'adeudos',
        'otros_conceptos',
        'total_calculado',
        'total_ajustado',
        'comentarios_ajuste',
        'snapshot',
        'estado',
        'documento_generado_path',
        'documento_firmado_path',
        'total_percepciones',
        'total_deducciones',
        'neto',
        'pagado_en',
        'pago_confirmado_por',
        'referencia_pago',
        'generated_document_id',
    ];

    /**
     * Conceptos capturados manualmente por RH además de los automáticos
     * (ver App\Services\Finiquitos\FiniquitoService::desglose()).
     *
     * @return HasMany<FiniquitoConcepto, $this>
     */
    public function conceptos(): HasMany
    {
        return $this->hasMany(FiniquitoConcepto::class, 'finiquito_calculo_id')->orderBy('id');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documentoGenerado(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_calculo' => 'datetime',
            'fecha_ingreso' => 'date',
            'fecha_baja' => 'date',
            'sueldo_mensual' => 'decimal:2',
            'sueldo_diario' => 'decimal:2',
            'antiguedad_anios' => 'integer',
            'antiguedad_meses' => 'integer',
            'dias_trabajados_periodo' => 'integer',
            'vacaciones_pendientes' => 'integer',
            'prima_vacacional' => 'decimal:2',
            'aguinaldo_proporcional' => 'decimal:2',
            'sueldo_pendiente' => 'decimal:2',
            'indemnizacion' => 'decimal:2',
            'bonos_extra' => 'decimal:2',
            'descuentos' => 'decimal:2',
            'adeudos' => 'decimal:2',
            'otros_conceptos' => 'array',
            'total_calculado' => 'decimal:2',
            'total_ajustado' => 'decimal:2',
            'snapshot' => 'array',
            'estado' => EstadoFiniquito::class,
            'total_percepciones' => 'decimal:2',
            'total_deducciones' => 'decimal:2',
            'neto' => 'decimal:2',
            'pagado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitudInterna(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class);
    }

    /**
     * Colaborador liquidado (fuente de verdad de persona/empleo — ver
     * App\Models\Colaborador). No es un User: calculadoPor()/revisadoPor()
     * son los actores que operan el cálculo, este es el sujeto liquidado.
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calculadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculado_por_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por_id');
    }
}
