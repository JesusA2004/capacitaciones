<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\EstadoUsuario;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string|null $apellidos
 * @property string|null $numero_empleado
 * @property string $email
 * @property string|null $telefono
 * @property string|null $foto_path
 * @property int|null $sucursal_principal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_id
 * @property int|null $jefe_id
 * @property Carbon|null $fecha_ingreso
 * @property EstadoUsuario $estatus
 * @property Carbon|null $ultimo_acceso
 * @property string $zona_horaria
 * @property array<string, mixed>|null $preferencias_notificaciones
 * @property Carbon|null $fecha_nacimiento
 * @property string|null $curp
 * @property string|null $rfc
 * @property string|null $nss
 * @property string|null $domicilio
 * @property string|null $correo_personal
 * @property string|null $contacto_emergencia_nombre
 * @property string|null $contacto_emergencia_telefono
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal|null $sucursalPrincipal
 * @property-read Departamento|null $departamento
 * @property-read Puesto|null $puesto
 * @property-read User|null $jefe
 */
#[Fillable([
    'name', 'apellidos', 'numero_empleado', 'email', 'password', 'telefono', 'foto_path',
    'sucursal_principal_id', 'departamento_id', 'puesto_id', 'jefe_id',
    'fecha_ingreso', 'estatus', 'zona_horaria', 'preferencias_notificaciones',
    'fecha_nacimiento', 'curp', 'rfc', 'nss', 'domicilio',
    'correo_personal', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

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
            'fecha_ingreso' => 'date',
            'estatus' => EstadoUsuario::class,
            'ultimo_acceso' => 'datetime',
            'preferencias_notificaciones' => 'array',
            'fecha_nacimiento' => 'date',
        ];
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->name} {$this->apellidos}");
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
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalPrincipal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_principal_id');
    }

    /**
     * Sucursales adicionales autorizadas, ademas de la principal.
     *
     * @return BelongsToMany<Sucursal, $this>
     */
    public function sucursalesAdicionales(): BelongsToMany
    {
        return $this->belongsToMany(Sucursal::class, 'sucursal_user');
    }

    /**
     * La empresa se resuelve de forma indirecta a traves de la sucursal
     * principal (no hay columna empresa_id propia en users): un colaborador
     * "pertenece" a la empresa de su sucursal. No es una relacion Eloquent
     * (no existe belongsTo-a-traves-de-belongsTo nativo) sino un helper de
     * lectura; para evitar N+1 al listar varios usuarios, carga la relacion
     * con `with(['sucursalPrincipal.empresa'])` antes de llamarlo. Ver
     * docs/MULTIEMPRESA.md.
     */
    public function empresa(): ?Empresa
    {
        return $this->sucursalPrincipal?->empresa;
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'user_id');
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
     * @return BelongsTo<User, $this>
     */
    public function jefe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jefe_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function subordinados(): HasMany
    {
        return $this->hasMany(User::class, 'jefe_id');
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
            ->logOnly(['name', 'apellidos', 'email', 'estatus', 'sucursal_principal_id', 'departamento_id', 'puesto_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
