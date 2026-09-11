<?php

namespace App\Models;

use App\Enums\TipoNodoComercial;
use Database\Factories\NodoComercialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nodo de la matriz comercial / territorial (MATRIZ -> Región -> Zona ->
 * Ruta) — ver docs/HEADCOUNT_Y_VACANTES.md, sección "Matriz comercial vs
 * Organigrama vs Vacantes". No confundir con App\Models\Puesto (catálogo de
 * puestos, ver Jerarquía de puestos) ni con headcount/vacantes (siguen
 * viviendo a nivel Sucursal, no por ruta).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property TipoNodoComercial $tipo
 * @property string $nombre
 * @property string|null $clave
 * @property string|null $region
 * @property bool $activa
 * @property int $orden
 * @property int|null $sucursal_id
 * @property int|null $puesto_id
 * @property int|null $responsable_user_id
 * @property array<string, mixed>|null $metadata
 */
class NodoComercial extends Model
{
    /** @use HasFactory<NodoComercialFactory> */
    use HasFactory;

    protected $table = 'nodos_comerciales';

    protected $fillable = [
        'parent_id',
        'tipo',
        'nombre',
        'clave',
        'region',
        'activa',
        'orden',
        'sucursal_id',
        'puesto_id',
        'responsable_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoNodoComercial::class,
            'activa' => 'boolean',
            'orden' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<NodoComercial, $this>
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<NodoComercial, $this>
     */
    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('orden')->orderBy('nombre');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * Gestor/responsable asignado a este nodo (típicamente una ruta). Un
     * usuario inactivo no cuenta como cobertura real — ver
     * App\Services\MatrizComercial\MatrizComercialService::cubierta().
     *
     * @return BelongsTo<User, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_user_id');
    }
}
