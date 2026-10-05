<?php

namespace App\Observers;

use App\Models\Colaborador;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Facades\DB;

/**
 * Plantilla ocupada = colaboradores vigentes por (sucursal, puesto)
 * (HeadcountService). Cualquier cambio que la mueva —alta, baja,
 * reactivación, cambio de puesto o de sucursal, venga de donde venga:
 * migración inicial, expediente, movimiento laboral, API— resincroniza la
 * vacante automática del par ORIGEN y del DESTINO. Antes solo lo hacían
 * algunos flujos y quedaban vacantes «1 plaza por cubrir» con la plaza ya
 * ocupada (docs/HEADCOUNT_Y_VACANTES.md).
 *
 * Corre al confirmar la transacción y nunca deshace la acción principal
 * (VacanteAutoGenerationService::sincronizarSinFallar()).
 */
class ColaboradorPlantillaObserver
{
    private const CAMPOS = ['estatus', 'puesto_id', 'sucursal_principal_id'];

    public function __construct(private readonly VacanteAutoGenerationService $vacantes) {}

    public function saved(Colaborador $colaborador): void
    {
        $cambio = $colaborador->wasChanged(self::CAMPOS);

        // wasRecentlyCreated sigue en true en la misma instancia después de
        // create(): solo cuenta como alta si este guardado no fue un update.
        if (! $cambio && ! ($colaborador->wasRecentlyCreated && $colaborador->getChanges() === [])) {
            return;
        }

        $pares = [[$colaborador->sucursal_principal_id, $colaborador->puesto_id]];

        if ($cambio) {
            // getPrevious(): valores antes de ESTE guardado (par de origen).
            $antes = $colaborador->getPrevious();
            $pares[] = [$antes['sucursal_principal_id'] ?? $colaborador->sucursal_principal_id, $antes['puesto_id'] ?? $colaborador->puesto_id];
        }

        $this->sincronizar($pares);
    }

    public function deleted(Colaborador $colaborador): void
    {
        $this->sincronizar([[$colaborador->sucursal_principal_id, $colaborador->puesto_id]]);
    }

    public function restored(Colaborador $colaborador): void
    {
        $this->sincronizar([[$colaborador->sucursal_principal_id, $colaborador->puesto_id]]);
    }

    /**
     * @param  list<array{0: mixed, 1: mixed}>  $pares
     */
    private function sincronizar(array $pares): void
    {
        $unicos = [];

        foreach ($pares as [$sucursalId, $puestoId]) {
            if (is_numeric($sucursalId) && is_numeric($puestoId)) {
                $unicos[sprintf('%d:%d', $sucursalId, $puestoId)] = [(int) $sucursalId, (int) $puestoId];
            }
        }

        if ($unicos === []) {
            return;
        }

        DB::afterCommit(function () use ($unicos): void {
            foreach ($unicos as [$sucursalId, $puestoId]) {
                $this->vacantes->sincronizarSinFallar($sucursalId, $puestoId);
            }
        });
    }
}
