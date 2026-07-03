<?php

namespace App\Models;

use App\Enums\EstadoIntentoCuestionario;
use Database\Factories\IntentoCuestionarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cuestionario_id
 * @property int $user_id
 * @property int $numero_intento
 * @property EstadoIntentoCuestionario $estado
 * @property Carbon $iniciado_en
 * @property Carbon|null $fecha_limite
 * @property Carbon|null $enviado_en
 * @property Carbon|null $calificado_en
 * @property int|null $calificacion
 * @property bool|null $aprobado
 * @property array<int, int>|null $orden_preguntas
 * @property array<int, array<int, int>>|null $orden_opciones
 * @property array<int, int>|null $puntaje_configurado
 */
class IntentoCuestionario extends Model
{
    /** @use HasFactory<IntentoCuestionarioFactory> */
    use HasFactory;

    protected $table = 'intentos_cuestionario';

    protected $fillable = [
        'cuestionario_id', 'user_id', 'numero_intento', 'estado',
        'iniciado_en', 'fecha_limite', 'enviado_en', 'calificado_en', 'calificacion', 'aprobado',
        'orden_preguntas', 'orden_opciones', 'puntaje_configurado',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoIntentoCuestionario::class,
            'iniciado_en' => 'datetime',
            'fecha_limite' => 'datetime',
            'enviado_en' => 'datetime',
            'calificado_en' => 'datetime',
            'aprobado' => 'boolean',
            'orden_preguntas' => 'array',
            'orden_opciones' => 'array',
            'puntaje_configurado' => 'array',
        ];
    }

    public function haExpirado(): bool
    {
        return $this->fecha_limite !== null && now()->greaterThan($this->fecha_limite);
    }

    /**
     * @return BelongsTo<Cuestionario, $this>
     */
    public function cuestionario(): BelongsTo
    {
        return $this->belongsTo(Cuestionario::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<RespuestaCuestionario, $this>
     */
    public function respuestas(): HasMany
    {
        return $this->hasMany(RespuestaCuestionario::class);
    }
}
