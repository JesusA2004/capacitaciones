<?php

namespace App\Models;

use App\Enums\EstadoAltaDigital;
use Database\Factories\AltaDigitalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $candidato_id
 * @property int|null $vacante_id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_id
 * @property string $token
 * @property Carbon|null $token_expira_en
 * @property EstadoAltaDigital $estado
 * @property string|null $nombre
 * @property string|null $apellidos
 * @property string|null $telefono
 * @property string|null $correo
 * @property Carbon|null $fecha_nacimiento
 * @property string|null $curp
 * @property string|null $rfc
 * @property string|null $nss
 * @property string|null $domicilio
 * @property string|null $contacto_emergencia_nombre
 * @property string|null $contacto_emergencia_telefono
 * @property Carbon|null $fecha_ingreso_propuesta
 * @property string|null $foto_disk
 * @property string|null $foto_path
 * @property string|null $foto_original_name
 * @property string|null $firma_disk
 * @property string|null $firma_path
 * @property bool $aviso_privacidad_aceptado
 * @property Carbon|null $aviso_privacidad_aceptado_en
 * @property bool $consentimiento_datos_aceptado
 * @property Carbon|null $consentimiento_datos_aceptado_en
 * @property Carbon|null $enviada_en
 * @property int|null $revisado_por
 * @property int|null $aprobado_por
 * @property string|null $motivo_rechazo
 * @property string|null $comentarios
 * @property int|null $user_id
 * @property int|null $creado_por
 */
class AltaDigital extends Model
{
    /** @use HasFactory<AltaDigitalFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'altas_digitales';

    /** Nunca se exponen al frontend: rutas fisicas del NAS. */
    protected $hidden = ['foto_disk', 'foto_path', 'firma_disk', 'firma_path'];

    protected $appends = ['tiene_foto', 'tiene_firma'];

    protected $fillable = [
        'candidato_id',
        'vacante_id',
        'empresa_id',
        'sucursal_id',
        'departamento_id',
        'puesto_id',
        'token',
        'token_expira_en',
        'estado',
        'nombre',
        'apellidos',
        'telefono',
        'correo',
        'fecha_nacimiento',
        'curp',
        'rfc',
        'nss',
        'domicilio',
        'contacto_emergencia_nombre',
        'contacto_emergencia_telefono',
        'fecha_ingreso_propuesta',
        'foto_disk',
        'foto_path',
        'foto_original_name',
        'firma_disk',
        'firma_path',
        'aviso_privacidad_aceptado',
        'aviso_privacidad_aceptado_en',
        'consentimiento_datos_aceptado',
        'consentimiento_datos_aceptado_en',
        'enviada_en',
        'revisado_por',
        'revisado_en',
        'aprobado_por',
        'aprobado_en',
        'motivo_rechazo',
        'comentarios',
        'user_id',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAltaDigital::class,
            'token_expira_en' => 'datetime',
            'fecha_nacimiento' => 'date',
            'fecha_ingreso_propuesta' => 'date',
            'aviso_privacidad_aceptado' => 'boolean',
            'aviso_privacidad_aceptado_en' => 'datetime',
            'consentimiento_datos_aceptado' => 'boolean',
            'consentimiento_datos_aceptado_en' => 'datetime',
            'enviada_en' => 'datetime',
            'revisado_en' => 'datetime',
            'aprobado_en' => 'datetime',
        ];
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellidos}");
    }

    public function tokenVigente(): bool
    {
        return $this->token_expira_en === null || $this->token_expira_en->isFuture();
    }

    public function getTieneFotoAttribute(): bool
    {
        return $this->foto_path !== null;
    }

    public function getTieneFirmaAttribute(): bool
    {
        return $this->firma_path !== null;
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<Vacante, $this>
     */
    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
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
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<AltaDigitalDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(AltaDigitalDocumento::class);
    }
}
