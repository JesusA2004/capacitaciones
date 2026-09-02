<?php

namespace App\Models;

use App\Enums\TipoPuesto;
use Database\Factories\PuestoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $nombre
 * @property int|null $departamento_id
 * @property string|null $descripcion
 * @property int|null $nivel_jerarquico
 * @property int|null $puesto_superior_id
 * @property int|null $puesto_crecimiento_id
 * @property TipoPuesto|null $tipo_puesto
 * @property string|null $esquema_comisiones
 * @property bool $requiere_ruta
 * @property string|null $responsabilidades
 * @property string|null $requisitos
 * @property bool $activo
 */
class Puesto extends Model
{
    /** @use HasFactory<PuestoFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nombre',
        'departamento_id',
        'descripcion',
        'nivel_jerarquico',
        'puesto_superior_id',
        'puesto_crecimiento_id',
        'tipo_puesto',
        'esquema_comisiones',
        'requiere_ruta',
        'responsabilidades',
        'requisitos',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'requiere_ruta' => 'boolean',
            'tipo_puesto' => TipoPuesto::class,
        ];
    }

    /**
     * @return BelongsTo<Departamento, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puestoSuperior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'puesto_superior_id');
    }

    /**
     * @return HasMany<Puesto, $this>
     */
    public function subordinadosDirectos(): HasMany
    {
        return $this->hasMany(self::class, 'puesto_superior_id');
    }

    /**
     * Puesto al que naturalmente se puede crecer desde este (ruta de crecimiento).
     *
     * @return BelongsTo<Puesto, $this>
     */
    public function puestoCrecimiento(): BelongsTo
    {
        return $this->belongsTo(self::class, 'puesto_crecimiento_id');
    }

    /**
     * Puestos cuya ruta natural de crecimiento llega a este puesto.
     *
     * @return HasMany<Puesto, $this>
     */
    public function puestosQueCrecenAqui(): HasMany
    {
        return $this->hasMany(self::class, 'puesto_crecimiento_id');
    }

    /**
     * Puestos que ESTE puesto puede cubrir (por ejemplo, Subgerente cubre Gerente).
     *
     * @return BelongsToMany<Puesto, $this>
     */
    public function puestosQuePuedeCubrir(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'puesto_cobertura',
            'puesto_id',
            'puesto_a_cubrir_id',
        )->withTimestamps();
    }

    /**
     * Respaldos de este puesto: puestos que pueden cubrirlo.
     *
     * @return BelongsToMany<Puesto, $this>
     */
    public function respaldos(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'puesto_cobertura',
            'puesto_a_cubrir_id',
            'puesto_id',
        )->withTimestamps();
    }

    /**
     * @return HasMany<Vacante, $this>
     */
    public function vacantes(): HasMany
    {
        return $this->hasMany(Vacante::class);
    }

    /**
     * @return HasMany<Candidato, $this>
     */
    public function candidatos(): HasMany
    {
        return $this->hasMany(Candidato::class, 'puesto_objetivo_id');
    }
}
