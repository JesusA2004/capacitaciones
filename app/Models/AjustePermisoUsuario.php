<?php

namespace App\Models;

use App\Enums\TipoAjustePermiso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permiso especial de una persona: concedido o quitado sin tocar su rol
 * (App\Services\Permisos\PermisosEspecialesService).
 *
 * @property int $id
 * @property int $user_id
 * @property string $permiso
 * @property TipoAjustePermiso $tipo
 * @property string|null $motivo
 * @property Carbon|null $vigente_hasta
 * @property int|null $otorgado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AjustePermisoUsuario extends Model
{
    protected $table = 'permisos_usuario_ajustes';

    protected $fillable = ['user_id', 'permiso', 'tipo', 'motivo', 'vigente_hasta', 'otorgado_por'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoAjustePermiso::class,
            'vigente_hasta' => 'datetime',
        ];
    }

    /**
     * Solo los que cuentan ahora: sin fecha de vencimiento o aún no vencidos.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function otorgadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'otorgado_por');
    }

    public function vigente(): bool
    {
        return $this->vigente_hasta === null || $this->vigente_hasta->isFuture();
    }
}
