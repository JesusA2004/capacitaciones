<?php

namespace App\Services\Headcount;

use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Support\Collection;

/**
 * «Plantilla autorizada» vista DESDE EL PUESTO (Puestos y salarios): en qué
 * ubicaciones —sucursales y Corporativo— tiene plazas, cuántas están
 * ocupadas y cuántas son vacantes. Mismas cifras que HeadcountService (una
 * sola regla); la captura se hace con PlantillaAutorizadaService, igual que
 * en el detalle de sucursal (mismo permiso headcount.editar y misma bitácora).
 *
 * Caso real: «Auditora · Contraloría · 1 persona» nunca tenía vacantes
 * porque solo recibía la plaza automática de PlazasEmpresaService; aquí RH
 * autoriza 4 en Corporativo y se abren 3 vacantes.
 *
 * @phpstan-type UbicacionPlantilla array{sucursal_id: int, sucursal: string, es_corporativo: bool, autorizadas: int, ocupadas: int, vacantes: int, excedentes: int}
 */
class PlantillaPuestoService
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly PuestosPlantillaService $puestos,
    ) {}

    /**
     * @return array{ubicaciones: list<UbicacionPlantilla>, totales: array{autorizadas: int, ocupadas: int, vacantes: int, excedentes: int}, es_de_empresa: bool, etiqueta_vacante: string, comparte_con: string|null, ubicaciones_disponibles: list<array{id: int, nombre: string, es_corporativo: bool}>, historial: list<array{id: int, sucursal: string|null, valor_anterior: int|null, valor_nuevo: int, fuente: string, motivo: string|null, usuario: string|null, fecha: string}>}
     */
    public function dePuesto(Puesto $puesto): array
    {
        $puestoId = $this->puestos->canonico($puesto->id);
        $autorizadas = $this->delPuesto($this->headcount->plantillaAutorizadaPorSucursalPuesto(), $puestoId);
        $ocupadas = $this->delPuesto($this->headcount->plantillaActualPorSucursalPuesto(), $puestoId);
        $sucursales = Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'es_corporativo', 'activo'])->keyBy('id');

        $ubicaciones = [];

        foreach (array_unique([...array_keys($autorizadas), ...array_keys($ocupadas)]) as $sucursalId) {
            $sucursal = $sucursales->get($sucursalId);
            $plazas = $autorizadas[$sucursalId] ?? 0;
            $personas = $ocupadas[$sucursalId] ?? 0;

            $ubicaciones[] = [
                'sucursal_id' => $sucursalId,
                'sucursal' => $sucursal !== null ? $sucursal->nombre : 'Ubicación eliminada',
                'es_corporativo' => (bool) ($sucursal->es_corporativo ?? false),
                'autorizadas' => $plazas,
                'ocupadas' => min($personas, $plazas),
                'vacantes' => max($plazas - $personas, 0),
                'excedentes' => max($personas - $plazas, 0),
            ];
        }

        // Corporativo primero, luego por nombre.
        usort($ubicaciones, fn (array $a, array $b): int => [! $a['es_corporativo'], $a['sucursal']] <=> [! $b['es_corporativo'], $b['sucursal']]);

        $compartido = array_values(array_filter($this->puestos->compartidos($puestoId), fn (int $id): bool => $id !== $puestoId))[0] ?? null;

        return [
            'ubicaciones' => $ubicaciones,
            'totales' => [
                'autorizadas' => array_sum(array_column($ubicaciones, 'autorizadas')),
                'ocupadas' => array_sum(array_column($ubicaciones, 'ocupadas')),
                'vacantes' => array_sum(array_column($ubicaciones, 'vacantes')),
                'excedentes' => array_sum(array_column($ubicaciones, 'excedentes')),
            ],
            'es_de_empresa' => $this->puestos->esDeEmpresa($puestoId),
            'etiqueta_vacante' => $this->puestos->etiquetaVacante($puesto->nombre) ?? $puesto->nombre,
            'comparte_con' => $compartido !== null ? Puesto::query()->whereKey($compartido)->value('nombre') : null,
            'ubicaciones_disponibles' => array_values($sucursales
                ->filter(fn (Sucursal $s): bool => $s->activo)
                ->sortBy(fn (Sucursal $s): string => sprintf('%d %s', $s->es_corporativo ? 0 : 1, $s->nombre))
                ->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre, 'es_corporativo' => $s->es_corporativo])
                ->values()
                ->all()),
            'historial' => $this->historial($puestoId, $sucursales),
        ];
    }

    /**
     * Plazas por puesto para el catálogo (Puestos y salarios): autorizadas,
     * ocupadas y vacantes en todas sus ubicaciones.
     *
     * @param  Collection<int, int>  $puestosIds
     * @return array<int, array{autorizadas: int, ocupadas: int, vacantes: int}>
     */
    public function resumenPorPuestos(Collection $puestosIds): array
    {
        if ($puestosIds->isEmpty()) {
            return [];
        }

        $autorizadas = $this->headcount->plantillaAutorizadaPorSucursalPuesto();
        $ocupadas = $this->headcount->plantillaActualPorSucursalPuesto();
        $resultado = [];

        foreach ($puestosIds as $id) {
            $puestoId = $this->puestos->canonico($id);
            $plazas = $this->delPuesto($autorizadas, $puestoId);
            $personas = $this->delPuesto($ocupadas, $puestoId);
            $fila = ['autorizadas' => 0, 'ocupadas' => 0, 'vacantes' => 0];

            foreach ($plazas as $sucursalId => $autorizada) {
                $actual = $personas[$sucursalId] ?? 0;
                $fila['autorizadas'] += $autorizada;
                $fila['ocupadas'] += min($actual, $autorizada);
                $fila['vacantes'] += max($autorizada - $actual, 0);
            }

            $resultado[$id] = $fila;
        }

        return $resultado;
    }

    /**
     * @param  Collection<string, int>  $porPar  clave "sucursal_id:puesto_id"
     * @return array<int, int> sucursal_id => total
     */
    private function delPuesto(Collection $porPar, int $puestoId): array
    {
        $resultado = [];

        foreach ($porPar as $clave => $total) {
            [$sucursalId, $puesto] = array_map('intval', explode(':', (string) $clave));

            if ($puesto === $puestoId) {
                $resultado[$sucursalId] = ($resultado[$sucursalId] ?? 0) + (int) $total;
            }
        }

        return $resultado;
    }

    /**
     * @param  Collection<int, Sucursal>  $sucursales
     * @return list<array{id: int, sucursal: string|null, valor_anterior: int|null, valor_nuevo: int, fuente: string, motivo: string|null, usuario: string|null, fecha: string}>
     */
    private function historial(int $puestoId, Collection $sucursales, int $limite = 30): array
    {
        return array_values(HeadcountTargetHistorial::query()
            ->where('puesto_id', $puestoId)
            ->with('usuario:id,name,apellidos')
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (HeadcountTargetHistorial $h): array => [
                'id' => $h->id,
                'sucursal' => $sucursales->get($h->sucursal_id)?->nombre,
                'valor_anterior' => $h->valor_anterior,
                'valor_nuevo' => $h->valor_nuevo,
                'fuente' => $h->fuente,
                'motivo' => $h->motivo,
                'usuario' => $h->usuario !== null ? trim(sprintf('%s %s', $h->usuario->name, $h->usuario->apellidos ?? '')) : null,
                'fecha' => $h->created_at->toIso8601String(),
            ])
            ->all());
    }
}
