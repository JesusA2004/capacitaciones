<?php

namespace App\Services\Headcount;

use App\Enums\EstadoUsuario;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Support\FechaMexico;
use Illuminate\Support\Facades\DB;

/**
 * Regla general: un puesto corporativo o regional activo, sin titular ni
 * cobertura vigente en toda la empresa, no se queda sin vacante. Se le autoriza
 * 1 plaza en Corporativo y se abre su vacante automática. Nunca pisa una plaza
 * ya capturada y un puesto con titular no recibe plaza (no hay nada que cubrir).
 *
 * Los puestos de sucursal sin plaza NO se inventan (no se puede adivinar la
 * sucursal): solo se listan para que RH los capture.
 */
class PlazasEmpresaService
{
    public function __construct(
        private readonly PuestosPlantillaService $puestos,
        private readonly VacanteAutoGenerationService $vacantes,
    ) {}

    /**
     * @return array{creadas: list<string>, sucursal_sin_plaza: list<string>, sin_corporativo: bool}
     */
    public function asegurar(bool $simular = false): array
    {
        $reporte = ['creadas' => [], 'sucursal_sin_plaza' => [], 'sin_corporativo' => false];
        $corporativo = Sucursal::query()->where('es_corporativo', true)->orderBy('id')->first();
        $excluidos = array_map('mb_strtolower', array_filter((array) config('headcount.puestos_sin_plaza_automatica', []), 'is_string'));
        $conPlaza = HeadcountTarget::query()->pluck('puesto_id')->map(fn ($id) => $this->puestos->canonico((int) $id))->unique()->all();

        foreach (Puesto::query()->where('activo', true)->orderBy('nombre')->get() as $puesto) {
            if (! $this->puestos->esCanonico($puesto->id) || in_array($puesto->id, $conPlaza, true) || in_array(mb_strtolower($puesto->nombre), $excluidos, true)) {
                continue;
            }

            $titulares = Colaborador::query()->whereIn('estatus', EstadoUsuario::valoresVigentes())->whereIn('puesto_id', $this->puestos->equivalentes($puesto->id))->count();

            if (! $this->puestos->esDeEmpresa($puesto->id)) {
                $reporte['sucursal_sin_plaza'][] = sprintf('%s (%d titular(es))', $puesto->nombre, $titulares);

                continue;
            }

            if ($titulares > 0 || CoberturaPuesto::query()->where('puesto_id', $puesto->id)->where('activa', true)->exists()) {
                continue;
            }

            if ($corporativo === null) {
                $reporte['sin_corporativo'] = true;

                continue;
            }

            $reporte['creadas'][] = $puesto->nombre;

            if (! $simular) {
                $this->crearPlaza($corporativo, $puesto);
            }
        }

        return $reporte;
    }

    /**
     * Tras poner o quitar una cobertura de un puesto de empresa: abre o cierra
     * su vacante en la sucursal donde vive la plaza.
     */
    public function resincronizar(int $puestoId): void
    {
        if (! $this->puestos->esDeEmpresa($puestoId)) {
            return;
        }

        foreach (HeadcountTarget::query()->where('puesto_id', $puestoId)->pluck('sucursal_id') as $sucursalId) {
            $this->vacantes->sincronizarSinFallar((int) $sucursalId, $puestoId);
        }
    }

    private function crearPlaza(Sucursal $sucursal, Puesto $puesto): void
    {
        DB::transaction(function () use ($sucursal, $puesto): void {
            $target = HeadcountTarget::query()->create([
                'empresa_id' => $sucursal->empresa_id,
                'sucursal_id' => $sucursal->id,
                'puesto_id' => $puesto->id,
                'plantilla_autorizada' => 1,
                'fuente' => 'organigrama',
                'fecha_corte' => FechaMexico::hoy(),
            ]);

            HeadcountTargetHistorial::query()->create([
                'headcount_target_id' => $target->id,
                'sucursal_id' => $sucursal->id,
                'puesto_id' => $puesto->id,
                'valor_anterior' => null,
                'valor_nuevo' => 1,
                'fuente' => 'organigrama',
                'motivo' => 'Puesto sin titular ni cobertura en la empresa: plaza automática.',
            ]);
        });

        $this->vacantes->sincronizarSinFallar($sucursal->id, $puesto->id);
    }
}
