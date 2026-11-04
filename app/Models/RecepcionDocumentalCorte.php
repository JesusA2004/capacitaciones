<?php

namespace App\Models;

use App\Enums\EstadoCorteRecepcion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Corte MENSUAL de Recepción documental RH: el mes calendario cuyos
 * originales firmados RH concilia (docs/RECEPCION_DOCUMENTAL_RH.md). Un
 * corte cerrado nunca se recalcula.
 *
 * @property int $id
 * @property string $periodo AAAA-MM
 * @property int $anio
 * @property int $mes
 * @property Carbon $fecha_inicio
 * @property Carbon $fecha_fin
 * @property EstadoCorteRecepcion $estado
 * @property Carbon|null $generado_en
 * @property int|null $generado_por
 * @property Carbon|null $materializado_en
 * @property Carbon|null $cerrado_en
 * @property int|null $cerrado_por
 * @property string|null $observaciones_cierre
 * @property int $reabierto_conteo
 * @property Carbon|null $reabierto_en
 * @property int|null $reabierto_por
 * @property string|null $motivo_reapertura
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecepcionDocumentalCorte extends Model
{
    protected $table = 'recepcion_documental_cortes';

    protected $fillable = [
        'periodo', 'anio', 'mes', 'fecha_inicio', 'fecha_fin', 'estado',
        'generado_en', 'generado_por', 'materializado_en',
        'cerrado_en', 'cerrado_por', 'observaciones_cierre',
        'reabierto_conteo', 'reabierto_en', 'reabierto_por', 'motivo_reapertura',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'en_preparacion',
        'reabierto_conteo' => 0,
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCorteRecepcion::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'generado_en' => 'datetime',
            'materializado_en' => 'datetime',
            'cerrado_en' => 'datetime',
            'reabierto_en' => 'datetime',
            'anio' => 'integer',
            'mes' => 'integer',
            'reabierto_conteo' => 'integer',
        ];
    }

    public function estaCerrado(): bool
    {
        return $this->estado === EstadoCorteRecepcion::Cerrado;
    }

    /**
     * @return HasMany<RecepcionDocumentalRenglon, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(RecepcionDocumentalRenglon::class, 'corte_id');
    }

    /**
     * @return HasMany<RecepcionDocumentalPdf, $this>
     */
    public function pdfs(): HasMany
    {
        return $this->hasMany(RecepcionDocumentalPdf::class, 'corte_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reabiertoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reabierto_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
