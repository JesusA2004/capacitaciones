<?php

namespace App\Observers;

use App\Models\Baja;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Services\Headcount\PuestosPlantillaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Facades\DB;

/**
 * Para la plantilla, una persona es baja desde el último día de su baja
 * registrada aunque su estatus laboral siga activo hasta que se entrega el
 * finiquito (HeadcountService::consultaOcupantes()). Por eso registrar,
 * cancelar («No procede»), cerrar o cambiar la fecha de una baja resincroniza
 * la vacante automática de su plaza al momento — sin esperar a que cambie el
 * estatus del colaborador (eso ya lo cubre ColaboradorPlantillaObserver). Una
 * baja con último día futuro la abre `people:sincronizar-vacantes` (diario)
 * cuando llega ese día.
 *
 * Corre al confirmar la transacción y nunca deshace la acción principal
 * (VacanteAutoGenerationService::sincronizarSinFallar()).
 */
class BajaPlantillaObserver
{
    public function __construct(
        private readonly VacanteAutoGenerationService $vacantes,
        private readonly PuestosPlantillaService $puestos,
    ) {}

    public function saved(Baja $baja): void
    {
        if (! $baja->wasRecentlyCreated && ! $baja->wasChanged(['estatus', 'fecha_baja', 'colaborador_id'])) {
            return;
        }

        $colaborador = $baja->colaborador_id !== null ? Colaborador::query()->where('id', $baja->colaborador_id)->first(['id', 'puesto_id', 'sucursal_principal_id']) : null;

        if ($colaborador === null || $colaborador->puesto_id === null || $colaborador->sucursal_principal_id === null) {
            return;
        }

        $puestoId = $this->puestos->canonico($colaborador->puesto_id);
        $pares = [
            [$colaborador->sucursal_principal_id, $colaborador->puesto_id],
            [$this->puestos->sucursalDePlaza($puestoId, $colaborador->sucursal_principal_id), $puestoId],
        ];

        CoberturaPuesto::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('activa', true)
            ->where('ocupa_plaza', true)
            ->get(['puesto_id', 'sucursal_id'])
            ->each(function (CoberturaPuesto $cobertura) use (&$pares): void {
                $pares[] = [$this->puestos->sucursalDeCobertura($cobertura->puesto_id, $cobertura->sucursal_id), $cobertura->puesto_id];
            });

        $unicos = [];

        foreach ($pares as [$sucursalId, $puesto]) {
            if ($sucursalId !== null) {
                $unicos[sprintf('%d:%d', $sucursalId, $puesto)] = [$sucursalId, $puesto];
            }
        }

        DB::afterCommit(function () use ($unicos): void {
            foreach ($unicos as [$sucursalId, $puesto]) {
                $this->vacantes->sincronizarSinFallar($sucursalId, $puesto);
            }
        });
    }
}
