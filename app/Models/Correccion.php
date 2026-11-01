<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Registro inmutable de una corrección aplicada (CorreccionService). Se suma a
 * activitylog, no lo sustituye: guarda motivo, huella y afectados confirmados.
 *
 * @property int $id
 * @property string $clave_idempotencia
 * @property string $dominio
 * @property string $sujeto_type
 * @property int $sujeto_id
 * @property int|null $colaborador_id
 * @property int $version
 * @property string $modo
 * @property string|null $tipo
 * @property string $huella_anterior
 * @property string $huella_nueva
 * @property string $motivo
 * @property Carbon|null $vigente_desde
 * @property array<int, array<string, mixed>>|null $afectados
 * @property string|null $resultado_type
 * @property int|null $resultado_id
 * @property string $origen
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int $actor_id
 * @property Carbon $created_at
 * @property-read User $actor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CorreccionCampo> $campos
 */
class Correccion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'correcciones';

    protected $fillable = [
        'clave_idempotencia', 'dominio', 'sujeto_type', 'sujeto_id', 'colaborador_id', 'version', 'modo', 'tipo',
        'huella_anterior', 'huella_nueva', 'motivo', 'vigente_desde', 'afectados', 'resultado_type', 'resultado_id',
        'origen', 'ip', 'user_agent', 'actor_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Una corrección registrada no se edita.'));
        static::deleting(fn (): never => throw new LogicException('Una corrección registrada no se borra.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'vigente_desde' => 'date',
            'afectados' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return HasMany<CorreccionCampo, $this>
     */
    public function campos(): HasMany
    {
        return $this->hasMany(CorreccionCampo::class);
    }
}
