<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PDF histórico ÚNICO de un expediente anterior al sistema (migración
 * inicial, docs/MIGRACION_INICIAL_EXPEDIENTES.md). Trae juntos solicitud,
 * fotos, acta, INE, contratos… en un solo archivo: se conserva tal cual
 * (sin cortarlo, sin OCR, sin copias por tipo). NO es un tipo de documento:
 * no cuenta en el checklist de 14 ni en el porcentaje del expediente.
 *
 * estado: `vinculado` (tiene colaborador) o `pendiente_vinculacion`
 * (carpeta del NAS sin persona identificable; RH lo vincula a mano, p. ej.
 * en un reingreso).
 *
 * @property int $id
 * @property int|null $colaborador_id
 * @property string $estado
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $stored_name
 * @property string|null $mime
 * @property string|null $extension
 * @property int|null $size
 * @property string $hash
 * @property string|null $source_disk
 * @property string $source_path
 * @property string|null $source_sucursal
 * @property string|null $source_carpeta
 * @property string|null $nombre_detectado
 * @property int|null $sucursal_id
 * @property int|null $migracion_id
 * @property Carbon|null $migrated_at
 * @property int|null $migrated_by
 * @property int|null $vinculado_por
 * @property Carbon|null $vinculado_en
 * @property-read Colaborador|null $colaborador
 */
class ExpedienteHistorico extends Model
{
    public const VINCULADO = 'vinculado';

    public const PENDIENTE = 'pendiente_vinculacion';

    protected $table = 'expedientes_historicos';

    protected $fillable = [
        'colaborador_id', 'estado', 'disk', 'path', 'original_name', 'stored_name', 'mime', 'extension', 'size', 'hash',
        'source_disk', 'source_path', 'source_sucursal', 'source_carpeta', 'nombre_detectado', 'sucursal_id',
        'migracion_id', 'migrated_at', 'migrated_by', 'vinculado_por', 'vinculado_en',
    ];

    /** Nunca se exponen rutas físicas: se sirve por endpoint autorizado. */
    protected $hidden = ['disk', 'path', 'source_disk', 'source_path'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'migrated_at' => 'datetime', 'vinculado_en' => 'datetime'];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
