<?php

namespace App\Models;

use Database\Factories\ImportacionExcelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Importación de docs/onboarding/REPORTE_TRABAJADORES_VAC.xlsx (salarios y
 * vacaciones históricas). `detalle` guarda cada fila ya resuelta por
 * ImportadorTrabajadoresService::analizar() — el preview y aplicar() leen
 * de aquí, nunca reinterpretan el archivo dos veces. `archivo_hash` es
 * único: el mismo archivo nunca se aplica dos veces.
 *
 * @property int $id
 * @property string $archivo_hash
 * @property string $nombre_archivo
 * @property string $tipo
 * @property int $subido_por
 * @property string $estado 'previsualizado'|'aplicado'
 * @property int $total_filas
 * @property int $filas_aplicadas
 * @property int $filas_revision
 * @property int $filas_sin_cambios
 * @property int $filas_no_encontradas
 * @property int $filas_error
 * @property list<array<string, mixed>>|null $detalle
 * @property array<string, mixed>|null $metadatos Hash del contenido, columnas detectadas/ignoradas y resumen de la aplicación.
 * @property Carbon|null $aplicado_en
 * @property int|null $aplicado_por
 * @property-read User $subidoPor
 * @property-read User|null $aplicadoPor
 */
class ImportacionExcel extends Model
{
    /** @use HasFactory<ImportacionExcelFactory> */
    use HasFactory;

    protected $table = 'importaciones_excel';

    protected $fillable = [
        'archivo_hash', 'nombre_archivo', 'tipo', 'subido_por', 'estado',
        'total_filas', 'filas_aplicadas', 'filas_revision', 'filas_sin_cambios',
        'filas_no_encontradas', 'filas_error', 'detalle', 'metadatos', 'aplicado_en', 'aplicado_por',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'metadatos' => 'array',
            'aplicado_en' => 'datetime',
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
     * @return HasMany<AjusteHistoricoVacaciones, $this>
     */
    public function ajustesVacaciones(): HasMany
    {
        return $this->hasMany(AjusteHistoricoVacaciones::class, 'importacion_id');
    }

    /**
     * @return HasMany<HistorialSalario, $this>
     */
    public function historialSalarios(): HasMany
    {
        return $this->hasMany(HistorialSalario::class, 'importacion_id');
    }
}
