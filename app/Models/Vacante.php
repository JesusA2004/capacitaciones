<?php

namespace App\Models;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use Database\Factories\VacanteFactory;
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
 * @property int|null $puesto_id
 * @property int|null $gerente_solicitante_id
 * @property int|null $responsable_rh_id
 * @property MotivoVacante $motivo
 * @property EstadoVacante $estado
 * @property Carbon $fecha_apertura
 * @property Carbon|null $fecha_estimada_cobertura
 * @property string|null $observaciones
 * @property int|null $creado_por
 */
class Vacante extends Model
{
    /** @use HasFactory<VacanteFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'departamento_id',
        'puesto_id',
        'gerente_solicitante_id',
        'responsable_rh_id',
        'motivo',
        'estado',
        'fecha_apertura',
        'fecha_estimada_cobertura',
        'observaciones',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'motivo' => MotivoVacante::class,
            'estado' => EstadoVacante::class,
            'fecha_apertura' => 'date',
            'fecha_estimada_cobertura' => 'date',
        ];
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
    public function gerenteSolicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_solicitante_id');
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
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<Candidato, $this>
     */
    public function candidatos(): HasMany
    {
        return $this->hasMany(Candidato::class);
    }
}
