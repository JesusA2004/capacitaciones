<?php

namespace App\Models;

use App\Enums\EstadoLoteContratos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una corrida de «Generar todos» del módulo Contratos
 * (App\Services\Contratos\LoteContratosService).
 *
 * @property int $id
 * @property int|null $iniciado_por
 * @property EstadoLoteContratos $estado
 * @property int $total
 * @property int $procesados
 * @property int $generados
 * @property int $sin_cambios
 * @property int $omitidos
 * @property int $errores
 * @property list<array<string, mixed>>|null $resultados
 * @property string|null $error
 * @property Carbon|null $iniciado_en
 * @property Carbon|null $terminado_en
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
class LoteGeneracionContratos extends Model
{
    protected $table = 'lotes_generacion_contratos';

    protected $fillable = [
        'iniciado_por', 'estado', 'total', 'procesados', 'generados', 'sin_cambios',
        'omitidos', 'errores', 'resultados', 'error', 'iniciado_en', 'terminado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoLoteContratos::class,
            'total' => 'integer',
            'procesados' => 'integer',
            'generados' => 'integer',
            'sin_cambios' => 'integer',
            'omitidos' => 'integer',
            'errores' => 'integer',
            'resultados' => 'array',
            'iniciado_en' => 'datetime',
            'terminado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }
}
