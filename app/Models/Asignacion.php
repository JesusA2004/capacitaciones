<?php

namespace App\Models;

use Database\Factories\AsignacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nombre
 * @property string $asignable_type
 * @property int $asignable_id
 * @property int $responsable_id
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_limite
 * @property bool $obligatoria
 * @property array<string, mixed>|null $recordatorios
 * @property array<string, mixed>|null $reglas
 * @property bool $activa
 * @property Carbon|null $cancelada_en
 */
class Asignacion extends Model
{
    /** @use HasFactory<AsignacionFactory> */
    use HasFactory;

    protected $table = 'asignaciones';

    protected $fillable = [
        'nombre', 'asignable_type', 'asignable_id', 'responsable_id',
        'fecha_inicio', 'fecha_limite', 'obligatoria', 'recordatorios', 'reglas',
        'activa', 'cancelada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_limite' => 'datetime',
            'obligatoria' => 'boolean',
            'recordatorios' => 'array',
            'reglas' => 'array',
            'activa' => 'boolean',
            'cancelada_en' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function asignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * @return HasMany<AsignacionDestino, $this>
     */
    public function destinos(): HasMany
    {
        return $this->hasMany(AsignacionDestino::class);
    }

    /**
     * @return HasMany<AsignacionUsuario, $this>
     */
    public function asignacionesUsuario(): HasMany
    {
        return $this->hasMany(AsignacionUsuario::class);
    }
}
