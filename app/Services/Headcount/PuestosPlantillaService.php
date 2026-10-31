<?php

namespace App\Services\Headcount;

use App\Models\HeadcountTarget;
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

    /** @var array<int, bool> */
    private array $deEmpresa = [];

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
     * Puesto de empresa (corporativo o regional): no desciende de un puesto
     * raíz de sucursal, así que se ocupa a nivel empresa, no por sucursal.
     */
    public function esDeEmpresa(int $puestoId): bool
    {
        if (isset($this->deEmpresa[$puestoId])) {
            return $this->deEmpresa[$puestoId];
        }

        $raices = array_map('mb_strtolower', array_filter((array) config('organigrama.puestos_raiz_sucursal', []), 'is_string'));
        $actual = Puesto::query()->where('id', $puestoId)->first(['id', 'nombre', 'puesto_superior_id']);
        $vistos = [];

        while ($actual !== null && ! isset($vistos[$actual->id])) {
            if (in_array(mb_strtolower($actual->nombre), $raices, true)) {
                return $this->deEmpresa[$puestoId] = false;
            }

            $vistos[$actual->id] = true;
            $actual = $actual->puesto_superior_id !== null ? Puesto::query()->where('id', $actual->puesto_superior_id)->first(['id', 'nombre', 'puesto_superior_id']) : null;
        }

        return $this->deEmpresa[$puestoId] = true;
    }

    /**
     * Sucursal donde cuenta una persona de este puesto: la suya o, si es de
     * empresa y su plaza vive en otra (Mónica en Tula, plaza en Corporativo),
     * la de la plaza. Así un titular registrado fuera no deja una vacante falsa.
     */
    public function sucursalDePlaza(int $puestoId, int $sucursalId): int
    {
        if (! $this->esDeEmpresa($puestoId)) {
            return $sucursalId;
        }

        $plazas = HeadcountTarget::query()->where('puesto_id', $puestoId)->where('plantilla_autorizada', '>', 0)->orderBy('id')->pluck('sucursal_id')->map(fn ($id) => (int) $id);

        return $plazas->isEmpty() || $plazas->contains($sucursalId) ? $sucursalId : (int) $plazas->first();
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
        $this->deEmpresa = [];
    }
}
