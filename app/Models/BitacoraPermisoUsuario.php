<?php

namespace App\Models;

use App\Enums\AccionAjustePermiso;
use App\Enums\TipoAjustePermiso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bitácora de permisos especiales de una persona: nunca se borra al
 * retirar un ajuste.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $permiso
 * @property AccionAjustePermiso $accion
 * @property TipoAjustePermiso|null $tipo
 * @property string|null $motivo
 * @property Carbon|null $vigente_hasta
 * @property int|null $actor_id
 * @property Carbon|null $created_at
 */
class BitacoraPermisoUsuario extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'permisos_usuario_bitacora';

    protected $fillable = ['user_id', 'permiso', 'accion', 'tipo', 'motivo', 'vigente_hasta', 'actor_id'];

    protected function casts(): array
    {
        return [
            'accion' => AccionAjustePermiso::class,
            'tipo' => TipoAjustePermiso::class,
            'vigente_hasta' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
