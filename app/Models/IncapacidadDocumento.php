<?php

namespace App\Models;

use App\Enums\TipoDocumentoIncapacidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Archivo original de una incapacidad en el NAS. Un reemplazo crea un renglón
 * nuevo: el anterior conserva su archivo y queda con `reemplazado_en`.
 *
 * @property int $id
 * @property int $incapacidad_id
 * @property TipoDocumentoIncapacidad $tipo
 * @property Carbon|null $fecha_documento
 * @property string $nombre_original
 * @property string $disco
 * @property string $ruta
 * @property string|null $mime
 * @property int $tamano
 * @property string|null $checksum
 * @property int|null $cargado_por
 * @property int|null $reemplaza_id
 * @property string|null $motivo_reemplazo
 * @property Carbon|null $reemplazado_en
 * @property Carbon|null $created_at
 * @property-read Incapacidad $incapacidad
 * @property-read User|null $cargadoPor
 */
class IncapacidadDocumento extends Model
{
    protected $table = 'incapacidad_documentos';

    protected $fillable = [
        'incapacidad_id', 'tipo', 'fecha_documento', 'nombre_original', 'disco', 'ruta', 'mime', 'tamano',
        'checksum', 'cargado_por', 'reemplaza_id', 'motivo_reemplazo', 'reemplazado_en',
    ];

    /**
     * El disco y la ruta nunca se exponen: la descarga pasa por la policy.
     *
     * @var list<string>
     */
    protected $hidden = ['disco', 'ruta'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumentoIncapacidad::class,
            'fecha_documento' => 'date',
            'tamano' => 'integer',
            'reemplazado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incapacidad, $this>
     */
    public function incapacidad(): BelongsTo
    {
        return $this->belongsTo(Incapacidad::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cargado_por');
    }
}
