<?php

namespace App\Models;

use App\Enums\EstadoCandidato;
use Database\Factories\CandidatoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_objetivo_id
 * @property int|null $vacante_id
 * @property string $nombre
 * @property string|null $apellidos
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $fuente
 * @property string|null $cv_disk
 * @property string|null $cv_path
 * @property string|null $cv_original_name
 * @property string|null $observaciones
 * @property string|null $documentos_solicitados
 * @property int|null $responsable_rh_id
 * @property int|null $gerente_involucrado_id
 * @property EstadoCandidato $estado
 * @property Carbon|null $fecha_entrevista
 * @property string|null $resultado_entrevista
 * @property int|null $creado_por
 */
class Candidato extends Model
{
    /** @use HasFactory<CandidatoFactory> */
    use HasFactory, SoftDeletes;

    /**
     * El disco/ruta reales del NAS nunca se exponen al frontend (ver
     * docs/SYNOLOGY_STORAGE.md); el cliente solo conoce tieneCv().
     */
    protected $hidden = ['cv_disk', 'cv_path'];

    protected $appends = ['tiene_cv'];

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'departamento_id',
        'puesto_objetivo_id',
        'vacante_id',
        'nombre',
        'apellidos',
        'telefono',
        'correo',
        'fuente',
        'cv_disk',
        'cv_path',
        'cv_original_name',
        'cv_mime',
        'cv_size',
        'observaciones',
        'documentos_solicitados',
        'responsable_rh_id',
        'gerente_involucrado_id',
        'estado',
        'fecha_entrevista',
        'resultado_entrevista',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCandidato::class,
            'fecha_entrevista' => 'datetime',
            'cv_size' => 'integer',
        ];
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellidos}");
    }

    public function getTieneCvAttribute(): bool
    {
        return $this->cv_path !== null;
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
    public function puestoObjetivo(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_objetivo_id');
    }

    /**
     * @return BelongsTo<Vacante, $this>
     */
    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsableRh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_rh_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function gerenteInvolucrado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_involucrado_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<SeguimientoCandidato, $this>
     */
    public function seguimientos(): HasMany
    {
        return $this->hasMany(SeguimientoCandidato::class)->orderByDesc('fecha');
    }
}
