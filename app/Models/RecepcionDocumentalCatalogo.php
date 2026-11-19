<?php

namespace App\Models;

use App\Enums\ModoCalculoRecepcion;
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
 * @property string|null $clave
 * @property ReglaRecepcionDocumental $regla
 * @property ModoCalculoRecepcion $modo_calculo
 * @property int $cantidad
 * @property ReglaRecepcionDocumental|null $regla_base
 * @property ReglaRecepcionDocumental|null $bloque
 * @property array<string, int>|null $copias
 * @property list<int>|null $departamento_ids
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
        'clave', 'regla', 'modo_calculo', 'cantidad', 'regla_base', 'bloque',
        'nombre', 'descripcion', 'documentos', 'copias', 'activa', 'orden',
        'sucursal_ids', 'empresa_ids', 'departamento_ids', 'actualizado_por',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modo_calculo' => 'regla',
        'cantidad' => 1,
        'activa' => true,
        'orden' => 0,
    ];

    protected function casts(): array
    {
        return [
            'regla' => ReglaRecepcionDocumental::class,
            'modo_calculo' => ModoCalculoRecepcion::class,
            'cantidad' => 'integer',
            'regla_base' => ReglaRecepcionDocumental::class,
            'bloque' => ReglaRecepcionDocumental::class,
            'documentos' => 'array',
            'copias' => 'array',
            'departamento_ids' => 'array',
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

    /** Entrada agregada por RH (no una regla del sistema). */
    public function esPersonalizada(): bool
    {
        return $this->modo_calculo->esPersonalizado();
    }

    /** Regla bajo cuyo bloque aparecen sus renglones en el corte. */
    public function bloqueEfectivo(): ReglaRecepcionDocumental
    {
        return $this->bloque ?? $this->regla;
    }

    /** Papeles por unidad (fijo / sucursal / colaborador / evento), mínimo 1. */
    public function cantidadUnitaria(): int
    {
        return max(1, (int) $this->cantidad);
    }

    /** Copias que se esperan de un documento de una regla del sistema (mínimo 1). */
    public function copiasDe(string $clave): int
    {
        $copias = (array) $this->copias;

        return max(1, (int) ($copias[$clave] ?? 1));
    }

    /**
     * @return list<int>
     */
    public function departamentos(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $this->departamento_ids), fn (int $id): bool => $id > 0)));
    }

    /**
     * ¿Este tipo de renglón pertenece a esta entrada agregada? (el tipo es su
     * clave o, en lotes semanales, «clave.AAAA-MM-DD»).
     */
    public function esDuenaDeTipo(string $tipoDocumento): bool
    {
        return $this->clave !== null && $this->esPersonalizada()
            && ($tipoDocumento === $this->clave || str_starts_with($tipoDocumento, $this->clave.'.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
