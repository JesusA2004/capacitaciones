<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\EstadoUsuario;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cuenta de acceso al sistema — email, password, 2FA, tokens, dispositivos,
 * roles, preferencias. Los datos de persona/empleo viven en
 * App\Models\Colaborador (`colaborador_id`), no aquí — ver
 * docs/ROLES_Y_NAVEGACION.md. `name`/`apellidos` se conservan como copia de
 * despliegue (para pantallas que muestran a este usuario como actor —
 * "revisado por", "subido por", etc. — sin necesitar cargar su colaborador);
 * `Colaborador::name` es la fuente autoritativa y se sincroniza aquí al
 * editar el expediente (ver Rh\ExpedienteController).
 *
 * @property int $id
 * @property int|null $colaborador_id
 * @property string $name
 * @property string|null $apellidos
 * @property string $email
 * @property Carbon|null $acceso_bloqueado_en
 * @property Carbon|null $ultimo_acceso
 * @property string $zona_horaria
 * @property array<string, mixed>|null $preferencias_notificaciones
 * @property array{tema_color: string, avatar_color: string, animaciones: bool}|null $preferencias_ui
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Colaborador|null $colaborador
 */
#[Fillable([
    'colaborador_id', 'name', 'apellidos', 'email', 'password',
    'zona_horaria', 'preferencias_notificaciones', 'preferencias_ui',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'acceso_bloqueado_en' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'preferencias_notificaciones' => 'array',
            'preferencias_ui' => 'array',
        ];
    }

    /**
     * Nombre para mostrar como actor (subido por, revisado por, etc.):
     * prefiere el del Colaborador enlazado (fuente autoritativa) y cae al
     * de la propia cuenta si no hay colaborador (cuentas de servicio).
     */
    public function nombreCompleto(): string
    {
        return $this->colaborador?->nombreCompleto() ?? trim("{$this->name} {$this->apellidos}");
    }

    /**
     * La app movil nunca deja pasar a un colaborador al portal normal
     * mientras no este Activo, y el acceso puede bloquearse
     * independientemente del estatus laboral (ver
     * App\Http\Controllers\Administracion\UsuarioController::revocarAcceso()).
     * EnIncorporacion sigue viendo solo la checklist de su expediente (ver
     * App\Services\Incorporacion\IncorporacionService).
     */
    public function puedeAccederPortal(): bool
    {
        if ($this->acceso_bloqueado_en !== null) {
            return false;
        }

        return $this->colaborador?->estatus === EstadoUsuario::Activo;
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * Preferencia de notificacion por correo para una clave dada
     * (asignaciones/calificaciones/sesiones/recordatorios). Ausente o sin
     * definir = habilitada por defecto; el usuario solo la desactiva
     * explicitamente. Las notificaciones internas (campana) nunca se
     * desactivan, solo el envio por correo.
     */
    public function prefiereNotificacionPorCorreo(string $clave): bool
    {
        return (bool) ($this->preferencias_notificaciones[$clave] ?? true);
    }

    /**
     * Dispositivos moviles registrados (push tokens), incluyendo los
     * revocados: filtrar con activos() donde solo interese el push vigente.
     *
     * @return HasMany<MobileDevice, $this>
     */
    public function mobileDevices(): HasMany
    {
        return $this->hasMany(MobileDevice::class, 'user_id');
    }

    /**
     * @return HasMany<AsignacionUsuario, $this>
     */
    public function asignacionesUsuario(): HasMany
    {
        return $this->hasMany(AsignacionUsuario::class, 'user_id');
    }

    /**
     * @return HasMany<InscripcionCurso, $this>
     */
    public function inscripcionesCurso(): HasMany
    {
        return $this->hasMany(InscripcionCurso::class, 'user_id');
    }

    /**
     * @return HasMany<ProgresoLeccion, $this>
     */
    public function progresoLecciones(): HasMany
    {
        return $this->hasMany(ProgresoLeccion::class, 'user_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'apellidos', 'email', 'colaborador_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
