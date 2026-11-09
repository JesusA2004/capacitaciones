<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\MotivoPuestoSinUbicar;
use App\Models\Colaborador;
use App\Models\Puesto;
use Illuminate\Support\Collection;

/**
 * Puestos ACTIVOS del catálogo a los que no se llega desde Dirección General
 * caminando por puestos activos (`puesto_superior_id`): sin «reporta a», con
 * un superior inactivo/eliminado o colgando de una rama suelta (o de un
 * ciclo). El árbol General no los puede dibujar en su lugar, así que se
 * listan en la bandeja «Puestos sin ubicar en el organigrama» para que RH los
 * coloque con «Ubicar / mover puesto» (UbicacionPuestoService).
 *
 * La raíz es el puesto de la vista corporativa de Inducción
 * (config organigrama.vistas_induccion.corporativo = «Dirección General»).
 *
 * @phpstan-type PuestoSinUbicar array{id: int, nombre: string, departamento: string|null, superior: string|null, motivo: string, motivo_etiqueta: string, ocupantes: list<string>, total_ocupantes: int}
 */
class PuestosSinUbicarService
{
    private const OCUPANTES_POR_PUESTO = 5;

    public function __construct(private readonly MiniOrganigramaService $miniOrganigrama) {}

    /**
     * @return list<PuestoSinUbicar>
     */
    public function listar(): array
    {
        $raiz = $this->miniOrganigrama->raizDeVista('corporativo');

        if ($raiz === null) {
            return [];
        }

        $todos = Puesto::query()->withTrashed()->with('departamento:id,nombre')->get(['id', 'nombre', 'puesto_superior_id', 'departamento_id', 'activo', 'deleted_at'])->keyBy('id');
        $activos = $todos->filter(fn (Puesto $p): bool => $p->activo && $p->deleted_at === null);
        $alcanzables = $this->alcanzables($raiz, $activos);
        $sueltos = $activos->reject(fn (Puesto $p): bool => isset($alcanzables[$p->id]))->sortBy('nombre');

        if ($sueltos->isEmpty()) {
            return [];
        }

        $ocupantes = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereIn('puesto_id', $sueltos->keys())
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos', 'puesto_id'])
            ->groupBy('puesto_id');

        $lista = [];

        foreach ($sueltos as $puesto) {
            $superior = $puesto->puesto_superior_id !== null ? $todos->get($puesto->puesto_superior_id) : null;
            $motivo = match (true) {
                $puesto->puesto_superior_id === null => MotivoPuestoSinUbicar::SinSuperior,
                $superior === null || ! $superior->activo || $superior->deleted_at !== null => MotivoPuestoSinUbicar::SuperiorInactivo,
                default => MotivoPuestoSinUbicar::RamaSuelta,
            };
            /** @var Collection<int, Colaborador> $personas */
            $personas = $ocupantes->get($puesto->id) ?? collect();

            $lista[] = [
                'id' => $puesto->id,
                'nombre' => $puesto->nombre,
                'departamento' => $puesto->departamento?->nombre,
                'superior' => $superior?->nombre,
                'motivo' => $motivo->value,
                'motivo_etiqueta' => $motivo->etiqueta(),
                'ocupantes' => array_values($personas->take(self::OCUPANTES_POR_PUESTO)->map(fn (Colaborador $c): string => $c->nombreCompleto())->all()),
                'total_ocupantes' => $personas->count(),
            ];
        }

        return $lista;
    }

    /**
     * Ids de los puestos activos a los que se llega desde la raíz.
     *
     * @param  Collection<int, Puesto>  $activos
     * @return array<int, true>
     */
    private function alcanzables(int $raiz, Collection $activos): array
    {
        if (! $activos->has($raiz)) {
            return [];
        }

        $hijos = [];

        foreach ($activos as $puesto) {
            if ($puesto->puesto_superior_id !== null) {
                $hijos[$puesto->puesto_superior_id][] = $puesto->id;
            }
        }

        $vistos = [$raiz => true];
        $pendientes = [$raiz];

        while ($pendientes !== []) {
            $actual = array_pop($pendientes);

            foreach ($hijos[$actual] ?? [] as $hijo) {
                if (! isset($vistos[$hijo])) {
                    $vistos[$hijo] = true;
                    $pendientes[] = $hijo;
                }
            }
        }

        return $vistos;
    }
}
