<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Definición de un reporte de «Mis reportes». No guarda datos: al ejecutarse
 * vuelve a leerlos y a revalidar permisos y alcance del usuario que lo corre.
 *
 * @property int $id
 * @property int $user_id
 * @property string $nombre
 * @property string $fuente
 * @property array<string, mixed> $definicion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $usuario
 */
class ReporteGuardado extends Model
{
    protected $table = 'reportes_guardados';

    protected $fillable = ['user_id', 'nombre', 'fuente', 'definicion'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['definicion' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
