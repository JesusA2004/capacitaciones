<?php

namespace App\Models;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusImss;
use App\Enums\Genero;
use App\Enums\TipoContratacion;
use Database\Factories\ColaboradorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Persona/colaborador: datos personales y de empleo (sucursal, departamento,
 * puesto, jefe, IMSS, periodo de prueba, expediente, CURP/RFC/NSS...).
 *
 * Separado de `User` (cuenta de acceso: email, password, 2FA, tokens,
 * dispositivos, roles) — un Colaborador puede existir sin cuenta (`user`
 * null) y una cuenta siempre pertenece exactamente a un Colaborador
 * (`User::colaborador_id`). Ver docs/ROLES_Y_NAVEGACION.md.
 *
 * @property int $id
 * @property string $name
 * @property string|null $apellidos
 * @property Genero|null $genero
 * @property string|null $numero_empleado
 * @property string|null $telefono
 * @property string|null $telefono_corporativo
 * @property string|null $sueldo_mensual
 * @property string|null $foto_path
 * @property string|null $expediente_storage_path
 * @property int|null $sucursal_principal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_id
 * @property int|null $jefe_id
 * @property int|null $gerente_id
 * @property TipoContratacion|null $tipo_contratacion
 * @property EstadoAltaColaborador|null $estado_alta
 * @property int|null $candidato_id
 * @property int|null $alta_registrada_por
 * @property Carbon|null $activado_en
 * @property Carbon|null $fecha_baja
 * @property Carbon|null $expediente_cerrado_en
 * @property int|null $expediente_cerrado_por
 * @property Carbon|null $fecha_ingreso
 * @property EstadoUsuario $estatus
 * @property EstatusImss $estatus_imss
 * @property Carbon|null $fecha_alta_imss
 * @property Carbon|null $periodo_prueba_inicio
 * @property Carbon|null $periodo_prueba_fin
 * @property Carbon|null $fecha_nacimiento
 * @property string|null $curp
 * @property string|null $rfc
 * @property string|null $nss
 * @property string|null $domicilio
 * @property string|null $correo_personal
 * @property string|null $contacto_emergencia_nombre
 * @property string|null $contacto_emergencia_telefono
 * @property string|null $incorporacion_decision
 * @property int|null $incorporacion_decidida_por
 * @property Carbon|null $incorporacion_decidida_en
 * @property string|null $incorporacion_motivo_rechazo
 * @property bool $aviso_privacidad_aceptado
 * @property Carbon|null $aviso_privacidad_aceptado_en
 * @property bool $consentimiento_datos_aceptado
 * @property Carbon|null $consentimiento_datos_aceptado_en
 * @property int|null $avisos_registrado_por_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal|null $sucursalPrincipal
 * @property-read Departamento|null $departamento
 * @property-read Puesto|null $puesto
 * @property-read Colaborador|null $jefe
 * @property-read Colaborador|null $gerente
 * @property-read User|null $user
 */
#[Fillable([
    'name', 'apellidos', 'genero', 'numero_empleado', 'telefono', 'telefono_corporativo', 'sueldo_mensual', 'foto_path',
    'sucursal_principal_id', 'departamento_id', 'puesto_id', 'jefe_id', 'gerente_id',
    'tipo_contratacion', 'estado_alta', 'candidato_id', 'alta_registrada_por', 'activado_en',
    'fecha_baja', 'expediente_cerrado_en', 'expediente_cerrado_por',
    'fecha_ingreso', 'estatus', 'estatus_imss', 'fecha_alta_imss',
    'periodo_prueba_inicio', 'periodo_prueba_fin',
    'fecha_nacimiento', 'curp', 'rfc', 'nss', 'domicilio',
    'correo_personal', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono',
    'aviso_privacidad_aceptado', 'aviso_privacidad_aceptado_en',
    'consentimiento_datos_aceptado', 'consentimiento_datos_aceptado_en', 'avisos_registrado_por_id',
    'incorporacion_decision', 'incorporacion_decidida_por', 'incorporacion_decidida_en', 'incorporacion_motivo_rechazo',
])]
class Colaborador extends Model
{
    /** @use HasFactory<ColaboradorFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'colaboradores';

    /**
     * Mismo motivo que en User: refleja en PHP el default real de la
     * columna en BD para que un Colaborador recien creado no truene el cast
     * a enum antes de recargarse.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estatus_imss' => 'pendiente_imss',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'estatus' => EstadoUsuario::class,
            'genero' => Genero::class,
            'estatus_imss' => EstatusImss::class,
            'fecha_alta_imss' => 'date',
            'periodo_prueba_inicio' => 'date',
            'periodo_prueba_fin' => 'date',
            'fecha_nacimiento' => 'date',
            'aviso_privacidad_aceptado' => 'boolean',
            'aviso_privacidad_aceptado_en' => 'datetime',
            'consentimiento_datos_aceptado' => 'boolean',
            'consentimiento_datos_aceptado_en' => 'datetime',
            'incorporacion_decidida_en' => 'datetime',
            'sueldo_mensual' => 'decimal:2',
            'tipo_contratacion' => TipoContratacion::class,
            'estado_alta' => EstadoAltaColaborador::class,
            'activado_en' => 'datetime',
            'fecha_baja' => 'date',
            'expediente_cerrado_en' => 'datetime',
        ];
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->name} {$this->apellidos}");
    }

    public function enPeriodoDePrueba(): bool
    {
        if ($this->periodo_prueba_inicio === null || $this->periodo_prueba_fin === null) {
            return false;
        }

        return now()->betweenIncluded($this->periodo_prueba_inicio, $this->periodo_prueba_fin);
    }

    /**
     * La empresa se resuelve de forma indirecta a traves de la sucursal
     * principal (no hay columna empresa_id propia) — ver docs/MULTIEMPRESA.md.
     */
    public function empresa(): ?Empresa
    {
        return $this->sucursalPrincipal?->empresa;
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'colaborador_id');
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
        return $this->belongsToMany(Sucursal::class, 'sucursal_colaborador');
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
     * @return BelongsTo<Colaborador, $this>
     */
    public function jefe(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'jefe_id');
    }

    /**
     * Gerente del colaborador (puede ser distinto del jefe inmediato). Ver
     * App\Services\Colaboradores\JerarquiaColaboradorService::gerenteDe().
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function gerente(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'gerente_id');
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidatoOrigen(): BelongsTo
    {
        return $this->belongsTo(Candidato::class, 'candidato_id');
    }

    /**
     * @return HasMany<ContratoLaboral, $this>
     */
    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoLaboral::class)->orderByDesc('fecha_inicio')->orderByDesc('id');
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documentosGenerados(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'colaborador_id');
    }

    /**
     * @return HasMany<Colaborador, $this>
     */
    public function subordinados(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'jefe_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function incorporacionDecididaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incorporacion_decidida_por');
    }

    /**
     * Quién registró manualmente el aviso de privacidad / consentimiento de
     * datos (ver App\Services\Expedientes\AvisoPrivacidadService) — solo
     * aplica a colaboradores sin Alta digital real.
     *
     * @return BelongsTo<User, $this>
     */
    public function avisosRegistradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avisos_registrado_por_id');
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'colaborador_id');
    }

    /**
     * Histórico de movimientos laborales de este colaborador (altas, bajas,
     * promociones, cambios de puesto/sucursal/departamento/jefe/empresa y
     * coberturas). Ver docs/MOVIMIENTOS_LABORALES.md.
     *
     * @return HasMany<MovimientoLaboral, $this>
     */
    public function movimientosLaborales(): HasMany
    {
        return $this->hasMany(MovimientoLaboral::class, 'colaborador_id')->orderByDesc('fecha_movimiento')->orderByDesc('id');
    }

    /**
     * @return HasMany<BirthdayGreeting, $this>
     */
    public function felicitacionesCumpleanos(): HasMany
    {
        return $this->hasMany(BirthdayGreeting::class, 'colaborador_id');
    }

    /**
     * @return HasMany<AsignacionNodoComercial, $this>
     */
    public function asignacionesNodoComercial(): HasMany
    {
        return $this->hasMany(AsignacionNodoComercial::class, 'colaborador_id');
    }

    /**
     * @return HasMany<ReciboNomina, $this>
     */
    public function recibosNomina(): HasMany
    {
        return $this->hasMany(ReciboNomina::class)->orderByDesc('periodo_inicio')->orderByDesc('id');
    }

    /**
     * @return HasMany<Prestamo, $this>
     */
    public function prestamos(): HasMany
    {
        return $this->hasMany(Prestamo::class)->orderByDesc('created_at');
    }

    /**
     * Préstamo vigente de este colaborador (el más reciente con
     * estado='activo'), si tiene alguno — usado para sugerir automáticamente
     * la línea de deducción de nómina al generar un recibo (ver
     * App\Services\Nomina\ReciboNominaService).
     */
    public function prestamoActivo(): ?Prestamo
    {
        return $this->prestamos()->where('estado', 'activo')->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'apellidos', 'estatus', 'sucursal_principal_id', 'departamento_id', 'puesto_id',
                'jefe_id', 'gerente_id', 'sueldo_mensual', 'fecha_ingreso', 'tipo_contratacion',
                'periodo_prueba_inicio', 'periodo_prueba_fin', 'estado_alta', 'fecha_baja',
                'expediente_cerrado_en', 'incorporacion_decision',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
