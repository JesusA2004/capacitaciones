<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una consulta de un cliente de integración a la API servidor-a-servidor
 * (bitácora; ver App\Services\Integraciones\BitacoraConsultasIntegracionService).
 * Sólo metadatos: nunca el token ni el cuerpo de la respuesta.
 *
 * @property int $id
 * @property int|null $cliente_integracion_id
 * @property string $metodo
 * @property string|null $ruta
 * @property string $url
 * @property array<string, mixed>|null $parametros
 * @property int $estado
 * @property int|null $registros
 * @property int|null $duracion_ms
 * @property string|null $ip
 * @property Carbon|null $created_at
 */
class ConsultaIntegracion extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'consultas_integracion';

    protected $fillable = ['cliente_integracion_id', 'metodo', 'ruta', 'url', 'parametros', 'estado', 'registros', 'duracion_ms', 'ip'];

    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'estado' => 'integer',
            'registros' => 'integer',
            'duracion_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ClienteIntegracion, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ClienteIntegracion::class, 'cliente_integracion_id');
    }

    /**
     * Retención de la bitácora (`php artisan model:prune`).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(max(30, (int) config('integraciones.sistemas.bitacora_retencion_dias', 365))));
    }
}
