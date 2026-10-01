<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo configurable de activos que se entregan al terminar el
 * onboarding (uniforme, casco, motocicleta, gafete... los define RH, no el
 * código). puesto_ids = null aplica a todos los puestos.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property bool $requiere_identificador
 * @property list<int>|null $puesto_ids
 * @property bool $obligatorio
 * @property string $plantilla_responsiva
 * @property bool $activo
 */
class TipoActivo extends Model
{
    protected $table = 'tipos_activo';

    protected $fillable = ['clave', 'nombre', 'requiere_identificador', 'puesto_ids', 'obligatorio', 'plantilla_responsiva', 'activo'];

    protected function casts(): array
    {
        return [
            'requiere_identificador' => 'boolean',
            'puesto_ids' => 'array',
            'obligatorio' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function aplicaAPuesto(?int $puestoId): bool
    {
        if ($this->puesto_ids === null || $this->puesto_ids === []) {
            return true;
        }

        return $puestoId !== null && in_array($puestoId, array_map('intval', $this->puesto_ids), true);
    }
}
