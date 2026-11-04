<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una generación del PDF operativo de un corte (variante «control» para
 * palomear a mano o «estado» con lo ya recibido). Versionado: nunca se
 * sobreescribe ni se borra la anterior.
 *
 * @property int $id
 * @property int $corte_id
 * @property string $variante
 * @property int $version
 * @property int|null $sucursal_id
 * @property string $disk
 * @property string $path
 * @property string $nombre
 * @property int $tamano
 * @property int|null $generado_por
 * @property Carbon|null $generado_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecepcionDocumentalPdf extends Model
{
    public const VARIANTE_CONTROL = 'control';

    public const VARIANTE_ESTADO = 'estado';

    protected $table = 'recepcion_documental_pdfs';

    protected $fillable = ['corte_id', 'variante', 'version', 'sucursal_id', 'disk', 'path', 'nombre', 'tamano', 'generado_por', 'generado_en'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'tamano' => 'integer',
            'generado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecepcionDocumentalCorte, $this>
     */
    public function corte(): BelongsTo
    {
        return $this->belongsTo(RecepcionDocumentalCorte::class, 'corte_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }
}
