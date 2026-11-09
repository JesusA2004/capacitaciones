<?php

namespace App\Services\Headcount;

use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Support\Collection;

/**
 * Reglas de "qué puesto es qué plaza" para plantilla y vacantes
 * (config/headcount.php). Única fuente de:
 *  - puestos_equivalentes: un puesto que cuenta como otro (misma plaza);
 *  - plazas_compartidas: dos puestos con plazas propias que se prestan
 *    lugar (Gestor volante ↔ Gestor), ver HeadcountService;
 *  - la etiqueta de un puesto en Vacantes («Gestor fijo», «Gestor volante»);
 *  - dónde vive la plaza de un puesto de empresa.
 */
class PuestosPlantillaService
{
    /** @var array<int, int>|null puesto_id equivalente → puesto_id de plantilla */
    private ?array $mapa = null;

    /** @var array<int, int>|null puesto_id → puesto_id con el que comparte plazas (ambos sentidos) */
    private ?array $compartidas = null;

    /** @var list<array{0: int, 1: int}>|null parejas [puesto A, puesto B] */
    private ?array $parejas = null;

    /** @var array<int, bool> */
    private array $deEmpresa = [];

    /** @var array<int, Puesto>|null Catálogo completo: subir por la jerarquía no consulta por eslabón. */
    private ?array $catalogoPuestos = null;

    /** @var array<int, Collection<int, int>>|null */
    private ?array $plazasPorPuesto = null;

    private int|false|null $corporativoId = null;

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
     * El puesto (de plantilla) y el que comparte plazas con él: un cambio en
     * cualquiera de los dos mueve las vacantes de ambos.
     *
     * @return list<int>
     */
    public function compartidos(int $puestoId): array
    {
        $canonico = $this->canonico($puestoId);
        $otro = $this->compartidasMapa()[$canonico] ?? null;

        return $otro === null ? [$canonico] : [$canonico, $otro];
    }

    /**
     * Parejas de puestos que se prestan plazas: [A, B] (A = el de la clave en
     * config, p. ej. Gestor Volante; B = Gestor).
     *
     * @return list<array{0: int, 1: int}>
     */
    public function parejasCompartidas(): array
    {
        $this->compartidasMapa();

        return $this->parejas ?? [];
    }

    /**
     * Nombre con el que se muestra el puesto en Vacantes (web, API, resumen,
     * Excel y PDF): «Gestor fijo», «Gestor volante»; los demás, su nombre.
     */
    public function etiquetaVacante(?string $nombre): ?string
    {
        if ($nombre === null) {
            return null;
        }

        $etiqueta = config(sprintf('headcount.etiquetas_vacante.%s', $nombre));

        return is_string($etiqueta) && $etiqueta !== '' ? $etiqueta : $nombre;
    }

    /**
     * Aclaración del puesto en Vacantes (p. ej. «la ruta se define en Matriz comercial»).
     */
    public function notaVacante(?string $nombre): ?string
    {
        if ($nombre === null) {
            return null;
        }

        $nota = config(sprintf('headcount.notas_vacante.%s', $nombre));

        return is_string($nota) && $nota !== '' ? $nota : null;
    }

    /**
     * Puesto cuyas plazas captura «¿Esta sucursal tendrá volantes?».
     */
    public function puestoVolante(): ?Puesto
    {
        $nombre = config('headcount.puesto_volante');

        return is_string($nombre) ? Puesto::query()->where('nombre', $nombre)->first() : null;
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
        $this->catalogoPuestos ??= Puesto::query()->get(['id', 'nombre', 'puesto_superior_id'])->keyBy('id')->all();
        $actual = $this->catalogoPuestos[$puestoId] ?? null;
        $vistos = [];

        while ($actual !== null && ! isset($vistos[$actual->id])) {
            if (in_array(mb_strtolower($actual->nombre), $raices, true)) {
                return $this->deEmpresa[$puestoId] = false;
            }

            $vistos[$actual->id] = true;
            $actual = $this->catalogoPuestos[$actual->puesto_superior_id ?? 0] ?? null;
        }

        return $this->deEmpresa[$puestoId] = true;
    }

    /**
     * Sucursal donde cuenta una persona de este puesto: la suya o, si es de
     * empresa y su plaza vive en otra (registrada en una sucursal, plaza en Corporativo),
     * la de la plaza. Así un titular registrado fuera no deja una vacante falsa.
     */
    public function sucursalDePlaza(int $puestoId, int $sucursalId): int
    {
        if (! $this->esDeEmpresa($puestoId)) {
            return $sucursalId;
        }

        $plazas = $this->plazasDe($puestoId);

        return $plazas->isEmpty() || $plazas->contains($sucursalId) ? $sucursalId : (int) $plazas->first();
    }

    /**
     * Sucursal de la plaza que ocupa una cobertura: la cubierta o, para un
     * puesto de empresa cubierto sin sucursal (corporativo o región), donde
     * vive su plaza (Corporativo si aún no tiene). Null = no hay a dónde contarla.
     */
    public function sucursalDeCobertura(int $puestoId, ?int $sucursalId): ?int
    {
        if ($sucursalId !== null) {
            return $this->sucursalDePlaza($puestoId, $sucursalId);
        }

        if (! $this->esDeEmpresa($puestoId)) {
            return null;
        }

        $plazas = $this->plazasDe($puestoId);

        if ($plazas->isNotEmpty()) {
            return (int) $plazas->first();
        }

        if ($this->corporativoId === null) {
            $id = Sucursal::query()->where('es_corporativo', true)->orderBy('id')->value('id');
            $this->corporativoId = $id !== null ? (int) $id : false;
        }

        return $this->corporativoId === false ? null : $this->corporativoId;
    }

    /**
     * @return Collection<int, int>
     */
    private function plazasDe(int $puestoId): Collection
    {
        $this->plazasPorPuesto ??= HeadcountTarget::query()->where('plantilla_autorizada', '>', 0)->orderBy('id')->get(['puesto_id', 'sucursal_id'])
            ->groupBy('puesto_id')
            ->map(fn (Collection $filas) => $filas->map(fn (HeadcountTarget $fila): int => (int) $fila->sucursal_id)->values())
            ->all();

        return $this->plazasPorPuesto[$puestoId] ?? collect();
    }

    /**
     * @return array<int, int>
     */
    private function mapa(): array
    {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $this->mapa = [];

        foreach ($this->parejasPorNombre('headcount.puestos_equivalentes') as [$origen, $destino]) {
            $this->mapa[$origen] = $destino;
        }

        return $this->mapa;
    }

    /**
     * @return array<int, int>
     */
    private function compartidasMapa(): array
    {
        if ($this->compartidas !== null) {
            return $this->compartidas;
        }

        $this->compartidas = [];
        $this->parejas = [];

        foreach ($this->parejasPorNombre('headcount.plazas_compartidas') as [$a, $b]) {
            $a = $this->canonico($a);
            $b = $this->canonico($b);

            if ($a === $b || isset($this->compartidas[$a]) || isset($this->compartidas[$b])) {
                continue;
            }

            $this->compartidas[$a] = $b;
            $this->compartidas[$b] = $a;
            $this->parejas[] = [$a, $b];
        }

        return $this->compartidas;
    }

    /**
     * Pares nombre => nombre de config traducidos a ids (los que no existen en el catálogo se omiten).
     *
     * @return list<array{0: int, 1: int}>
     */
    private function parejasPorNombre(string $clave): array
    {
        $config = [];

        foreach ((array) config($clave, []) as $origen => $destino) {
            if (is_string($origen) && is_string($destino)) {
                $config[$origen] = $destino;
            }
        }

        if ($config === []) {
            return [];
        }

        $ids = Puesto::query()->whereIn('nombre', [...array_keys($config), ...array_values($config)])->pluck('id', 'nombre');
        $parejas = [];

        foreach ($config as $origen => $destino) {
            if (isset($ids[$origen], $ids[$destino])) {
                $parejas[] = [(int) $ids[$origen], (int) $ids[$destino]];
            }
        }

        return $parejas;
    }

    public function olvidar(): void
    {
        $this->mapa = null;
        $this->compartidas = null;
        $this->parejas = null;
        $this->deEmpresa = [];
        $this->catalogoPuestos = null;
        $this->plazasPorPuesto = null;
        $this->corporativoId = null;
    }
}
