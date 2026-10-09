<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Arte o documento de una campaña (imagen del anuncio, PDF del copy…),
 * guardado en el NAS privado; solo se sirve autenticado.
 *
 * @property int $id
 * @property int $campana_reclutamiento_id
 * @property string $disk
 * @property string $path
 * @property string $nombre_original
 * @property string $mime
 * @property int $tamano
 * @property int|null $subido_por
 * @property Carbon|null $created_at
 * @property-read CampanaReclutamiento $campana
 */
class CampanaReclutamientoAdjunto extends Model
{
    protected $table = 'campana_reclutamiento_adjuntos';

    protected $fillable = ['campana_reclutamiento_id', 'disk', 'path', 'nombre_original', 'mime', 'tamano', 'subido_por'];

    /**
     * @var list<string>
     */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['tamano' => 'integer'];
    }

    /**
     * @return BelongsTo<CampanaReclutamiento, $this>
     */
    public function campana(): BelongsTo
    {
        return $this->belongsTo(CampanaReclutamiento::class, 'campana_reclutamiento_id');
    }
}
