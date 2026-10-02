<?php

namespace App\Services\Headcount;

use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captura de la plantilla autorizada por (sucursal, puesto) — solo RH
 * (permiso headcount.editar, ver SucursalPolicy::editarPlantilla). Cada
 * cambio deja su renglón en `headcount_target_historial` (quién, cuándo,
 * antes, después, motivo) y resincroniza la vacante automática del par.
 *
 * Se captura sobre el PUESTO DE PLANTILLA (PuestosPlantillaService): si
 * había plazas registradas en un puesto equivalente (Gestor volante → Gestor)
 * se integran al de plantilla para que el total sea exactamente el
 * capturado.
 */
class PlantillaAutorizadaService
{
    public function __construct(
        private readonly PuestosPlantillaService $puestos,
        private readonly VacanteAutoGenerationService $vacantes,
    ) {}

    public function actualizar(Sucursal $sucursal, Puesto $puesto, int $plantilla, string $motivo, User $actor): HeadcountTarget
    {
        $puestoId = $this->puestos->canonico($puesto->id);

        $target = DB::transaction(function () use ($sucursal, $puestoId, $plantilla, $motivo, $actor): HeadcountTarget {
            $filas = HeadcountTarget::query()
                ->where('sucursal_id', $sucursal->id)
                ->whereIn('puesto_id', $this->puestos->equivalentes($puestoId))
                ->lockForUpdate()
                ->get();

            $anterior = $filas->isEmpty() ? null : (int) $filas->sum('plantilla_autorizada');
            $existente = $filas->firstWhere('puesto_id', $puestoId);

            $target = HeadcountTarget::query()->updateOrCreate(
                ['sucursal_id' => $sucursal->id, 'puesto_id' => $puestoId],
                [
                    'empresa_id' => $sucursal->empresa_id,
                    'plantilla_autorizada' => $plantilla,
                    'fuente' => 'captura',
                    'fecha_corte' => Carbon::today(),
                    'updated_by_id' => $actor->id,
                    'created_by_id' => $existente->created_by_id ?? $actor->id,
                ],
            );

            // Las plazas del puesto equivalente quedan integradas aquí.
            HeadcountTarget::query()
                ->where('sucursal_id', $sucursal->id)
                ->whereIn('puesto_id', $this->puestos->equivalentes($puestoId))
                ->where('puesto_id', '!=', $puestoId)
                ->update(['plantilla_autorizada' => 0, 'updated_by_id' => $actor->id]);

            if ($anterior !== $plantilla) {
                HeadcountTargetHistorial::query()->create([
                    'headcount_target_id' => $target->id,
                    'sucursal_id' => $sucursal->id,
                    'puesto_id' => $puestoId,
                    'valor_anterior' => $anterior,
                    'valor_nuevo' => $plantilla,
                    'fuente' => 'captura',
                    'motivo' => $motivo,
                    'user_id' => $actor->id,
                ]);
            }

            return $target;
        });

        // La vacante automática se ajusta al nuevo número; si falla, la
        // plantilla ya quedó guardada (headcount:importar / la siguiente
        // alta o baja vuelven a sincronizar).
        try {
            $this->vacantes->sincronizar($sucursal->id, $puestoId);
        } catch (Throwable $e) {
            Log::warning('PlantillaAutorizadaService: no se pudo sincronizar la vacante automática.', ['sucursal_id' => $sucursal->id, 'puesto_id' => $puestoId, 'error' => $e->getMessage()]);
        }

        return $target;
    }

    /**
     * Últimos cambios de plantilla de la sucursal, más reciente primero.
     *
     * @return list<array{id: int, puesto: string|null, valor_anterior: int|null, valor_nuevo: int, fuente: string, motivo: string|null, usuario: string|null, fecha: string}>
     */
    public function historial(Sucursal $sucursal, int $limite = 50): array
    {
        return array_values(HeadcountTargetHistorial::query()
            ->where('sucursal_id', $sucursal->id)
            ->with(['puesto:id,nombre', 'usuario:id,name,apellidos'])
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (HeadcountTargetHistorial $h) => [
                'id' => $h->id,
                'puesto' => $h->puesto?->nombre,
                'valor_anterior' => $h->valor_anterior,
                'valor_nuevo' => $h->valor_nuevo,
                'fuente' => $h->fuente,
                'motivo' => $h->motivo,
                'usuario' => $h->usuario !== null ? trim(sprintf('%s %s', $h->usuario->name, $h->usuario->apellidos ?? '')) : null,
                'fecha' => $h->created_at->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Puestos que se pueden capturar en plantilla (activos y de plantilla:
     * nunca un equivalente como Gestor volante).
     *
     * @return Collection<int, array{id: int, nombre: string}>
     */
    public function puestosCapturables(): Collection
    {
        return Puesto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->filter(fn (Puesto $p) => $this->puestos->esCanonico($p->id))
            ->map(fn (Puesto $p) => ['id' => $p->id, 'nombre' => $p->nombre])
            ->values();
    }
}
