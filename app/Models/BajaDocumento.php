<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Archivo adjunto a una baja (PDF o imagen) en el espacio de un documento
 * requerido (o como documento adicional). Quitar un adjunto lo retira de la
 * baja (soft delete + bitácora); el archivo físico se conserva.
 *
 * @property int $id
 * @property int $baja_id
 * @property int|null $documento_requerido_id
 * @property string $nombre
 * @property string $nombre_original
 * @property string $disco
 * @property string $ruta
 * @property string|null $mime
 * @property int $tamano
 * @property int|null $cargado_por
 * @property int|null $quitado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Baja $baja
 * @property-read User|null $cargadoPor
 */
class BajaDocumento extends Model
{
    use SoftDeletes;

    protected $table = 'baja_documentos';

    protected $fillable = ['baja_id', 'documento_requerido_id', 'nombre', 'nombre_original', 'disco', 'ruta', 'mime', 'tamano', 'cargado_por', 'quitado_por'];

    protected $hidden = ['disco', 'ruta'];

    protected function casts(): array
    {
        return ['tamano' => 'integer'];
    }

    /**
     * @return BelongsTo<Baja, $this>
     */
    public function baja(): BelongsTo
    {
        return $this->belongsTo(Baja::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }
}
