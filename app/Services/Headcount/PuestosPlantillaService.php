<?php

namespace App\Services\Headcount;

use App\Models\Puesto;

/**
 * Traduce un puesto a su "puesto de plantilla" (config/headcount.php →
 * puestos_equivalentes): Gestor volante cuenta como Gestor para plantilla
 * autorizada, plantilla actual y vacantes. Única fuente de esa regla.
 */
class PuestosPlantillaService
{
    /** @var array<int, int>|null puesto_id equivalente → puesto_id de plantilla */
    private ?array $mapa = null;

    public function canonico(int $puestoId): int
    {
        return $this->mapa()[$puestoId] ?? $puestoId;
    }

    public function esCanonico(int $puestoId): bool
    {
        return ! isset($this->mapa()[$puestoId]);
    }

    /**
     * El puesto de plantilla y todos los que cuentan como él.
     *
     * @return list<int>
     */
    public function equivalentes(int $puestoId): array
    {
        $canonico = $this->canonico($puestoId);
        $ids = [$canonico];

        foreach ($this->mapa() as $equivalente => $destino) {
            if ($destino === $canonico) {
                $ids[] = $equivalente;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function mapa(): array
    {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $config = [];

        foreach ((array) config('headcount.puestos_equivalentes', []) as $origen => $destino) {
            if (is_string($origen) && is_string($destino)) {
                $config[$origen] = $destino;
            }
        }

        $ids = Puesto::query()->whereIn('nombre', [...array_keys($config), ...array_values($config)])->pluck('id', 'nombre');
        $this->mapa = [];

        foreach ($config as $origen => $destino) {
            if (isset($ids[$origen], $ids[$destino])) {
                $this->mapa[(int) $ids[$origen]] = (int) $ids[$destino];
            }
        }

        return $this->mapa;
    }

    public function olvidar(): void
    {
        $this->mapa = null;
    }
}
