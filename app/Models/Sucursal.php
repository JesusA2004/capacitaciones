<?php

namespace App\Models;

use Database\Factories\SucursalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int|null $empresa_id
 * @property string $nombre
 * @property string $clave
 * @property string|null $direccion
 * @property string|null $ciudad
 * @property string|null $estado
 * @property string|null $telefono
 * @property int|null $responsable_id
 * @property bool $activo
 * @property-read Empresa|null $empresa
 * @property-read User|null $responsable
 */
class Sucursal extends Model
{
    /** @use HasFactory<SucursalFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'sucursales';

    protected $fillable = ['empresa_id', 'nombre', 'clave', 'direccion', 'ciudad', 'estado', 'telefono', 'responsable_id', 'activo'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Colaboradores cuya sucursal principal es esta.
     *
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'sucursal_principal_id');
    }

    /**
     * Colaboradores con acceso adicional autorizado a esta sucursal
     * (ademas de su sucursal principal).
     *
     * @return BelongsToMany<User, $this>
     */
    public function usuariosAdicionales(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sucursal_user');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['empresa_id', 'nombre', 'clave', 'direccion', 'ciudad', 'estado', 'telefono', 'responsable_id', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
