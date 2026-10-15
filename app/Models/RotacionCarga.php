<?php

namespace App\Models;

use App\Enums\EstadoCargaRotacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un Excel de rotación de personal subido por RH (ver
 * App\Services\Rotacion\RotacionImportService). `detalle` guarda los
 * periodos y hallazgos que encontró el análisis: la vista previa y
 * aplicar() leen de aquí, nunca reinterpretan el archivo dos veces. Las
 * cargas no se borran: son la auditoría de qué cambió y cuándo.
 *
 * @property int $id
 * @property string $nombre_archivo
 * @property string $archivo_ruta
 * @property string $archivo_hash
 * @property EstadoCargaRotacion $estado
 * @property int $subido_por
 * @property int|null $aplicado_por
 * @property Carbon|null $aplicado_en
 * @property int $periodos_detectados
 * @property int $periodos_nuevos
 * @property int $periodos_reemplazados
 * @property int $periodos_sin_cambios
 * @property int $hallazgos
 * @property array<string, mixed>|null $resumen
 * @property array{periodos?: list<array<string, mixed>>, hallazgos?: list<array<string, mixed>>}|null $detalle
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $subidoPor
 * @property-read User|null $aplicadoPor
 */
class RotacionCarga extends Model
{
    protected $table = 'rotacion_cargas';

    protected $fillable = [
        'nombre_archivo', 'archivo_ruta', 'archivo_hash', 'estado', 'subido_por',
        'aplicado_por', 'aplicado_en', 'periodos_detectados', 'periodos_nuevos',
        'periodos_reemplazados', 'periodos_sin_cambios', 'hallazgos', 'resumen', 'detalle',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCargaRotacion::class,
            'aplicado_en' => 'datetime',
            'resumen' => 'array',
            'detalle' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aplicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicado_por');
    }

    /**
     * @return HasMany<RotacionPeriodo, $this>
     */
    public function periodos(): HasMany
    {
        return $this->hasMany(RotacionPeriodo::class, 'carga_id');
    }
}
