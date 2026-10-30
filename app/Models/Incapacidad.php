<?php

namespace App\Models;

use App\Enums\SecuenciaIncapacidad;
use App\Enums\SubtipoRiesgoTrabajo;
use App\Enums\TipoIncapacidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Incapacidad IMSS capturada por RH (App\Services\Incapacidades\IncapacidadService).
 * El riesgo de trabajo no tiene tabla propia: su inicial lleva `riesgo_folio`,
 * el evento y la conclusión, y las subsecuentes cuelgan de ella.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property int|null $sucursal_id
 * @property TipoIncapacidad $tipo
 * @property SubtipoRiesgoTrabajo|null $subtipo_riesgo
 * @property SecuenciaIncapacidad $secuencia
 * @property int|null $incapacidad_inicial_id
 * @property string $serie_folio_imss
 * @property string|null $folio_vigente
 * @property Carbon $fecha_inicio
 * @property int $dias
 * @property Carbon $fecha_fin
 * @property string|null $riesgo_folio
 * @property Carbon|null $fecha_evento
 * @property string|null $descripcion_evento
 * @property Carbon|null $riesgo_concluido_en
 * @property string|null $observaciones
 * @property int|null $registrado_por
 * @property int|null $actualizado_por
 * @property Carbon|null $cancelada_en
 * @property int|null $cancelada_por
 * @property string|null $motivo_cancelacion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador $colaborador
 * @property-read Sucursal|null $sucursal
 * @property-read Incapacidad|null $inicial
 * @property-read User|null $registradoPor
 */
class Incapacidad extends Model
{
    protected $table = 'incapacidades';

    protected $fillable = [
        'colaborador_id', 'sucursal_id', 'tipo', 'subtipo_riesgo', 'secuencia', 'incapacidad_inicial_id',
        'serie_folio_imss', 'folio_vigente', 'fecha_inicio', 'dias', 'fecha_fin', 'riesgo_folio', 'fecha_evento',
        'descripcion_evento', 'riesgo_concluido_en', 'observaciones', 'registrado_por', 'actualizado_por',
        'cancelada_en', 'cancelada_por', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIncapacidad::class,
            'subtipo_riesgo' => SubtipoRiesgoTrabajo::class,
            'secuencia' => SecuenciaIncapacidad::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_evento' => 'date',
            'riesgo_concluido_en' => 'date',
            'dias' => 'integer',
            'cancelada_en' => 'datetime',
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
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Incapacidad, $this>
     */
    public function inicial(): BelongsTo
    {
        return $this->belongsTo(self::class, 'incapacidad_inicial_id');
    }

    /**
     * @return HasMany<Incapacidad, $this>
     */
    public function subsecuentes(): HasMany
    {
        return $this->hasMany(self::class, 'incapacidad_inicial_id');
    }

    /**
     * @return HasMany<IncapacidadDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(IncapacidadDocumento::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function raizId(): int
    {
        return $this->incapacidad_inicial_id ?? $this->id;
    }

    public function estaCancelada(): bool
    {
        return $this->cancelada_en !== null;
    }
}
