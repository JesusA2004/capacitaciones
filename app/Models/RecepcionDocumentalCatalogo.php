<?php

namespace App\Models;

use App\Enums\ReglaRecepcionDocumental;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrada del catálogo configurable de Recepción documental RH: QUÉ se
 * espera recibir por cada evento de origen (docs/RECEPCION_DOCUMENTAL_RH.md
 * → «Catálogo de lo que se recibe»). Una por regla. La edita quien tenga
 * `recepcion_documental.configurar` (RecepcionDocumentalCatalogoPolicy).
 *
 * @property int $id
 * @property ReglaRecepcionDocumental $regla
 * @property string $nombre
 * @property string|null $descripcion
 * @property list<string>|null $documentos
 * @property bool $activa
 * @property int $orden
 * @property list<int>|null $sucursal_ids
 * @property list<int>|null $empresa_ids
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecepcionDocumentalCatalogo extends Model
{
    protected $table = 'recepcion_documental_catalogo';

    protected $fillable = [
        'regla', 'nombre', 'descripcion', 'documentos', 'activa', 'orden',
        'sucursal_ids', 'empresa_ids', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'regla' => ReglaRecepcionDocumental::class,
            'documentos' => 'array',
            'activa' => 'boolean',
            'orden' => 'integer',
            'sucursal_ids' => 'array',
            'empresa_ids' => 'array',
        ];
    }

    /**
     * Claves de documento configuradas (sin vacíos ni duplicados).
     *
     * @return list<string>
     */
    public function claves(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) $this->documentos), fn (string $c): bool => $c !== '')));
    }

    /**
     * @return list<int>
     */
    public function sucursales(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $this->sucursal_ids), fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<int>
     */
    public function empresas(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $this->empresa_ids), fn (int $id): bool => $id > 0)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
