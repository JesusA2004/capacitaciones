<?php

namespace App\Services\Organigrama;

use App\Models\Puesto;
use Illuminate\Support\Collection;

/**
 * Catálogo de áreas para "mini-organigramas" (config/organigrama.php →
 * 'areas': slug => nombre del puesto raíz) y el cálculo de qué puestos
 * cuelgan de un puesto raíz dado (raiz_puesto_id), sobre el árbol REAL de
 * Puesto::puesto_superior_id — nunca una imagen fija ni personas fijas.
 *
 * Usado por JerarquiaPuestoService::arbol() y OrganigramaPersonasService::arbol()
 * para recortar el árbol completo a la rama de un área, y por
 * Portal\OrganigramaController para listar las áreas disponibles (incluida
 * la inducción, capítulo 4).
 */
class MiniOrganigramaService
{
    /**
     * Áreas configuradas que sí existen en el catálogo real (una entrada
     * cuyo nombre no calza con ningún puesto activo se omite, nunca se
     * inventa un puesto).
     *
     * @return list<array{slug: string, etiqueta: string, puesto_id: int}>
     */
    public function areas(): array
    {
        /** @var array<string, string> $config */
        $config = config('organigrama.areas', []);
        $porNombre = Puesto::query()->where('activo', true)->pluck('id', 'nombre');

        $areas = [];

        foreach ($config as $slug => $nombrePuesto) {
            $id = $porNombre->get($nombrePuesto);

            if ($id !== null) {
                $areas[] = ['slug' => $slug, 'etiqueta' => $nombrePuesto, 'puesto_id' => (int) $id];
            }
        }

        return $areas;
    }

    /**
     * Id del puesto raíz y de todos los que cuelgan de él (directa o
     * indirectamente) según Puesto::puesto_superior_id. Incluye puestos
     * inactivos como posibles padres: un puesto retirado no debe "perder"
     * en el árbol a quien siga reportando ahí.
     *
     * @return list<int>
     */
    public function descendientes(int $raizPuestoId): array
    {
        $hijosDe = [];

        foreach (Puesto::query()->whereNotNull('puesto_superior_id')->get(['id', 'puesto_superior_id']) as $fila) {
            $hijosDe[(int) $fila->puesto_superior_id][] = $fila->id;
        }

        $ids = [$raizPuestoId];
        $pendientes = [$raizPuestoId];

        while ($pendientes !== []) {
            $actual = array_pop($pendientes);

            foreach ($hijosDe[$actual] ?? [] as $hijoId) {
                if (! in_array($hijoId, $ids, true)) {
                    $ids[] = $hijoId;
                    $pendientes[] = $hijoId;
                }
            }
        }

        return $ids;
    }

    /**
     * Puesto raíz (id) de una vista de inducción (config organigrama.vistas_induccion,
     * capítulo 4 de Inducción Mr. Lana). Null si la vista no existe o su
     * puesto ya no está activo en el catálogo — nunca se inventa.
     */
    public function raizDeVista(string $vista): ?int
    {
        $nombrePuesto = config("organigrama.vistas_induccion.{$vista}");

        if (! is_string($nombrePuesto)) {
            return null;
        }

        $id = Puesto::query()->where('activo', true)->where('nombre', $nombrePuesto)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Arma el árbol anidado (puesto, ocupantes, hijos) a partir de la lista
     * PLANA que entrega JerarquiaPuestoService::arbol()+agregarOcupantes()
     * ya recortada a una raíz (whereIn 'id', descendientes($raizId)).
     *
     * @param  Collection<int, Puesto>  $puestos
     * @return array<string, mixed>|null
     */
    public function anidar(Collection $puestos, int $raizId): ?array
    {
        $raiz = $puestos->firstWhere('id', $raizId);

        return $raiz !== null ? $this->nodo($raiz, $puestos) : null;
    }

    /**
     * @param  Collection<int, Puesto>  $puestos
     * @return array<string, mixed>
     */
    private function nodo(Puesto $puesto, Collection $puestos): array
    {
        return [
            'id' => $puesto->id,
            'nombre' => $puesto->nombre,
            'area' => $puesto->departamento?->nombre,
            'ocupantes' => $puesto->getAttribute('ocupantes') ?? [],
            'hijos' => $puestos->where('puesto_superior_id', $puesto->id)
                ->map(fn (Puesto $hijo) => $this->nodo($hijo, $puestos))
                ->values()
                ->all(),
        ];
    }
}
