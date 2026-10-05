<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una corrida de la migración inicial de expedientes: el análisis (dry
 * run, no toca BD ni NAS), las decisiones manuales de RH y la ejecución
 * (con su manifiesto). Ver App\Services\Expedientes\MigracionInicial.
 *
 * estado: analizado → aplicando → completado | fallido.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $archivo_nombre
 * @property string|null $archivo_path
 * @property string $archivo_hash
 * @property string $modo
 * @property string $estado
 * @property array<string, int>|null $totales
 * @property string|null $plan
 * @property array<string, mixed>|null $decisiones
 * @property array<string, mixed>|null $resultado
 * @property string|null $manifiesto_path
 * @property int $progreso
 * @property int $progreso_total
 * @property string|null $error
 * @property string|null $etapa
 * @property string|null $credenciales_path
 * @property Carbon|null $iniciada_en
 * @property Carbon|null $terminada_en
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
class MigracionExpedientes extends Model
{
    protected $table = 'migraciones_expedientes';

    protected $fillable = [
        'user_id', 'archivo_nombre', 'archivo_path', 'archivo_hash', 'modo', 'estado', 'totales', 'plan', 'decisiones',
        'resultado', 'manifiesto_path', 'progreso', 'progreso_total', 'error', 'iniciada_en', 'terminada_en', 'etapa', 'credenciales_path',
    ];

    protected $hidden = ['archivo_path', 'plan', 'manifiesto_path', 'credenciales_path'];

    protected function casts(): array
    {
        return [
            'totales' => 'array',
            'decisiones' => 'array',
            'resultado' => 'array',
            'progreso' => 'integer',
            'progreso_total' => 'integer',
            'iniciada_en' => 'datetime',
            'terminada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function planArray(): array
    {
        $plan = json_decode((string) $this->plan, true);

        return is_array($plan) ? $plan : [];
    }
}
