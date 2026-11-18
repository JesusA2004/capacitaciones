<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\EstadoUsuario;
use App\Enums\TipoAjustePermiso;
use App\Models\Concerns\NombreSinApellidos;
use App\Services\Autenticacion\NombreUsuarioService;
use App\Support\NombrePersona;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Contracts\Permission as PermisoContrato;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cuenta de acceso al sistema — username, password, 2FA, tokens,
 * dispositivos, roles, preferencias. Se inicia sesión con `username`
 * («Jesus Arizmendi», ver App\Services\Autenticacion\NombreUsuarioService y
 * docs/AUTENTICACION.md); `email` es opcional (contacto y recuperación de
 * contraseña) y nunca se inventa. Los datos de persona/empleo viven en
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
 * @property string $username
 * @property string|null $email
 * @property bool $debe_cambiar_contrasena
 * @property Carbon|null $acceso_bloqueado_en
 * @property string|null $acceso_bloqueado_motivo
 * @property int|null $acceso_bloqueado_por
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AjustePermisoUsuario> $ajustesPermisosVigentes
 */
#[Fillable([
    'colaborador_id', 'username', 'name', 'apellidos', 'email', 'password',
    'zona_horaria', 'preferencias_notificaciones', 'preferencias_ui',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, NombreSinApellidos, Notifiable, SoftDeletes;

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
            'debe_cambiar_contrasena' => 'boolean',
            'acceso_bloqueado_en' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'preferencias_notificaciones' => 'array',
            'preferencias_ui' => 'array',
        ];
    }

    /**
     * Toda cuenta nace con username: quien la crea sin uno explícito (alta
     * digital, administración, QR, seeders…) recibe el de la regla oficial
     * a partir de su colaborador. Después de creado nunca se cambia solo.
     * Aquí y no en el evento `creating`: los seeders corren con
     * WithoutModelEvents y saveQuietly() tampoco dispara eventos.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        if (trim((string) $this->username) === '') {
            $this->username = app(NombreUsuarioService::class)->paraCuenta($this);
        }

        return parent::performInsert($query);
    }

    /**
     * Nombre para mostrar como actor (subido por, revisado por, etc.):
     * prefiere el del Colaborador enlazado (fuente autoritativa) y cae al
     * de la propia cuenta si no hay colaborador (cuentas de servicio).
     */
    public function nombreCompleto(): string
    {
        return $this->colaborador?->nombreCompleto() ?? NombrePersona::completo($this->name, $this->apellidos);
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
     * Sucursales de las que esta cuenta no quiere recibir avisos
     * (SucursalesSilenciadasService).
     *
     * @return BelongsToMany<Sucursal, $this>
     */
    public function sucursalesSilenciadas(): BelongsToMany
    {
        return $this->belongsToMany(Sucursal::class, 'notificacion_sucursales_silenciadas')->withTimestamps();
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

    // ─────────────────────────────────────────────────────────────────────
    // Permisos especiales por persona (PermisosEspecialesService): UN solo
    // punto donde se aplican, encima de lo que dan sus roles. Spatie solo
    // sabe «conceder»; aquí se hace cumplir también el «quitar» en todos sus
    // caminos: can()/Gate (Spatie llama checkPermissionTo → hasPermissionTo),
    // hasPermissionTo/hasAnyPermission directos, getAllPermissions() (menú
    // web, app móvil) y User::permission() (a quién se avisa por permiso).
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Permisos especiales que cuentan hoy (sin vencer).
     *
     * @return HasMany<AjustePermisoUsuario, $this>
     */
    public function ajustesPermisosVigentes(): HasMany
    {
        return $this->hasMany(AjustePermisoUsuario::class, 'user_id')->vigentes();
    }

    /**
     * Todos los permisos especiales, incluidos los ya vencidos (bitácora).
     *
     * @return HasMany<AjustePermisoUsuario, $this>
     */
    public function ajustesPermisos(): HasMany
    {
        return $this->hasMany(AjustePermisoUsuario::class, 'user_id');
    }

    public function ajustePermiso(string $permiso): ?TipoAjustePermiso
    {
        // Sin cuenta guardada no hay ajustes (evita una consulta).
        if (! $this->exists) {
            return null;
        }

        return $this->ajustesPermisosVigentes->firstWhere('permiso', $permiso)?->tipo;
    }

    /**
     * Lo de Spatie (directo + roles activos) con los ajustes de la persona:
     * un permiso QUITADO nunca cuenta; uno CONCEDIDO cuenta aunque su rol no
     * lo tenga.
     *
     * @param  string|int|PermisoContrato|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $permiso = $this->filterPermission($permission, $guardName);

        return match ($this->ajustePermiso((string) $permiso->getAttribute('name'))) {
            TipoAjustePermiso::Revocar => false,
            TipoAjustePermiso::Conceder => true,
            null => $this->hasDirectPermission($permiso) || $this->hasPermissionViaRole($permiso),
        };
    }

    /**
     * Roles DESACTIVADOS (borrado lógico en «Roles y permisos») no otorgan
     * nada: la persona conserva la asignación, pero sus permisos no cuentan.
     */
    protected function hasPermissionViaRole(PermisoContrato $permission): bool
    {
        $activos = $this->rolesActivosIds();

        return $activos !== [] && $permission->roles->contains(fn (Model $rol): bool => in_array((int) $rol->getKey(), $activos, true));
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getPermissionsViaRoles(): Collection
    {
        $permisos = [];

        foreach ($this->loadMissing('roles', 'roles.permissions')->roles as $rol) {
            if ($rol->getAttribute('desactivado_en') !== null) {
                continue;
            }

            foreach ($rol->getRelationValue('permissions') ?? [] as $permiso) {
                if ($permiso instanceof Permission) {
                    $permisos[] = $permiso;
                }
            }
        }

        return collect($permisos)->sort()->values();
    }

    /**
     * @return list<int>
     */
    public function rolesActivosIds(): array
    {
        return array_values(array_map(
            fn ($id): int => (int) $id,
            $this->loadMissing('roles')->roles->filter(fn (Model $rol): bool => $rol->getAttribute('desactivado_en') === null)->modelKeys(),
        ));
    }

    /**
     * Permisos efectivos (directos + roles activos + concedidos − quitados).
     *
     * @return Collection<int, Permission>
     */
    public function getAllPermissions(): Collection
    {
        /** @var Collection<int, Permission> $base */
        $base = $this->permissions->merge($this->getPermissionsViaRoles());

        if (! $this->exists || $this->ajustesPermisosVigentes->isEmpty()) {
            return $base->sort()->values();
        }

        $quitados = $this->ajustesPermisosVigentes->where('tipo', TipoAjustePermiso::Revocar)->pluck('permiso')->all();
        $concedidos = $this->ajustesPermisosVigentes->where('tipo', TipoAjustePermiso::Conceder)->pluck('permiso')->all();

        return $base
            ->merge(Permission::query()->whereIn('name', $concedidos)->where('guard_name', $this->getDefaultGuardName())->get())
            ->unique('id')
            ->reject(fn (Permission $permiso): bool => in_array($permiso->name, $quitados, true))
            ->sort()
            ->values();
    }

    /**
     * Cuentas con CUALQUIERA de estos permisos, ya con los ajustes por
     * persona aplicados: la usan los avisos y pendientes «a quien tenga el
     * permiso X» (User::permission()).
     *
     * @param  Builder<static>  $query
     * @param  string|int|array<int, mixed>|PermisoContrato|Collection<int, mixed>|\BackedEnum  $permissions
     * @return Builder<static>
     */
    public function scopePermission(Builder $query, $permissions, bool $without = false): Builder
    {
        if ($without) {
            return $query->whereNot(fn (Builder $q) => $this->scopePermission($q, $permissions));
        }

        $permisos = $this->convertToPermissionModels($permissions);

        return $query->where(function (Builder $q) use ($permisos): void {
            foreach ($permisos as $permiso) {
                $id = $permiso->getKey();
                $nombre = (string) $permiso->getAttribute('name');
                $roles = $permiso->roles->modelKeys();

                $q->orWhere(fn (Builder $uno) => $uno
                    ->where(fn (Builder $otorga) => $otorga
                        ->whereHas('permissions', fn (Builder $p) => $p->where('permissions.id', $id))
                        // Solo roles activos: uno desactivado no otorga nada.
                        ->when($roles !== [], fn (Builder $r) => $r->orWhereHas('roles', fn (Builder $rol) => $rol->whereIn('roles.id', $roles)->whereNull('roles.desactivado_en')))
                        ->orWhereIn('users.id', AjustePermisoUsuario::query()->vigentes()->where('permiso', $nombre)->where('tipo', TipoAjustePermiso::Conceder->value)->select('user_id')))
                    ->whereNotIn('users.id', AjustePermisoUsuario::query()->vigentes()->where('permiso', $nombre)->where('tipo', TipoAjustePermiso::Revocar->value)->select('user_id')));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['username', 'name', 'apellidos', 'email', 'colaborador_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
