<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Valor de configuración que el negocio cambió desde Administración →
 * Configuración. El catálogo (tipo, validación, defecto) vive en
 * config/configuracion_sistema.php.
 *
 * @property int $id
 * @property string $clave
 * @property string|null $valor
 * @property string $tipo
 * @property string $grupo
 * @property string|null $descripcion
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $actualizadoPor
 */
class ConfiguracionSistema extends Model
{
    protected $table = 'configuraciones_sistema';

    protected $fillable = ['clave', 'valor', 'tipo', 'grupo', 'descripcion', 'actualizado_por'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
