<?php

namespace App\Models;

use App\Enums\EstadoInvitacionIncorporacion;
use Database\Factories\IncorporacionInvitacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Invitacion de incorporacion por QR temporal (ver
 * App\Services\Incorporacion\IncorporacionInvitacionService). Solo guarda el
 * hash del token (`token_hash`, sha256 del token plano) — el token plano
 * nunca se persiste, solo vive en la URL del QR que ve RH al crearla.
 *
 * @property int $id
 * @property string $uuid
 * @property string $token_hash
 * @property string|null $codigo_legible
 * @property string|null $email
 * @property string|null $telefono
 * @property string|null $nombre_prellenado
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_id
 * @property int|null $candidato_id
 * @property int|null $user_id
 * @property int $creado_por_id
 * @property int|null $usado_por_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property int|null $regenerated_from_id
 * @property int $max_usos
 * @property int $usos_count
 * @property EstadoInvitacionIncorporacion $estado
 * @property array<string, mixed>|null $metadata
 */
class IncorporacionInvitacion extends Model
{
    /** @use HasFactory<IncorporacionInvitacionFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'incorporacion_invitaciones';

    /** El token plano nunca se guarda; el hash tampoco se expone al frontend. */
    protected $hidden = ['token_hash'];

    protected $fillable = [
        'uuid', 'token_hash', 'codigo_legible',
        'email', 'telefono', 'nombre_prellenado',
        'empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'candidato_id',
        'user_id', 'creado_por_id', 'usado_por_id',
        'expires_at', 'used_at', 'revoked_at', 'regenerated_from_id',
        'max_usos', 'usos_count', 'estado', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'estado' => EstadoInvitacionIncorporacion::class,
            'metadata' => 'array',
            'max_usos' => 'integer',
            'usos_count' => 'integer',
        ];
    }

    public function tieneUsosDisponibles(): bool
    {
        return $this->usos_count < $this->max_usos;
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Departamento, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usado_por_id');
    }

    /**
     * @return BelongsTo<IncorporacionInvitacion, $this>
     */
    public function regeneradaDesde(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'usos_count', 'creado_por_id', 'usado_por_id', 'user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
