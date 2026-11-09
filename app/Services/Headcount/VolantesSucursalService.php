<?php

namespace App\Services\Headcount;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * «¿Esta sucursal tendrá volantes?» (Administración → Sucursales): fija las
 * plazas autorizadas del puesto Gestor Volante de la sucursal (0 si no
 * tendrá) con PlantillaAutorizadaService —misma bitácora y mismo permiso
 * headcount.editar— y eso abre o cierra sus vacantes «Gestor volante».
 *
 * Las plazas de volante son propias (ya no se suman a las de Gestor), pero
 * se prestan lugar con las de Gestor (config headcount.plazas_compartidas):
 * un volante sin plaza de volante ocupa una de Gestor.
 */
class VolantesSucursalService
{
    public function __construct(
        private readonly PlantillaAutorizadaService $plantilla,
        private readonly PuestosPlantillaService $puestos,
        private readonly HeadcountService $headcount,
    ) {}

    public function configurar(Sucursal $sucursal, int $cantidad, string $motivo, User $actor): HeadcountTarget
    {
        $volante = $this->puestoVolante();

        return $this->plantilla->actualizar($sucursal, $volante, max($cantidad, 0), $motivo, $actor);
    }

    /**
     * Gestores fijos (con ruta, Matriz comercial) vs. volantes de la sucursal.
     *
     * @return array{disponible: bool, volantes: array{puesto_id: int|null, autorizadas: int, ocupadas: int, vacantes: int, personas: int}, fijos: array{puesto_id: int|null, autorizadas: int, ocupadas: int, vacantes: int, personas: int}}
     */
    public function resumen(Sucursal $sucursal): array
    {
        $volante = $this->puestos->puestoVolante();
        $fijo = $volante !== null ? $this->compartidoCon($volante->id) : null;

        return [
            'disponible' => $volante !== null,
            'volantes' => $this->par($sucursal, $volante?->id),
            'fijos' => $this->par($sucursal, $fijo),
        ];
    }

    /**
     * Plazas de volante autorizadas por sucursal (para el listado).
     *
     * @param  list<int>  $sucursalesIds
     * @return array<int, int>
     */
    public function autorizadasPorSucursal(array $sucursalesIds): array
    {
        $volante = $this->puestos->puestoVolante();

        if ($volante === null || $sucursalesIds === []) {
            return [];
        }

        return HeadcountTarget::query()
            ->where('puesto_id', $volante->id)
            ->whereIn('sucursal_id', $sucursalesIds)
            ->pluck('plantilla_autorizada', 'sucursal_id')
            ->mapWithKeys(fn ($total, $sucursalId): array => [(int) $sucursalId => (int) $total])
            ->all();
    }

    private function puestoVolante(): Puesto
    {
        $volante = $this->puestos->puestoVolante();

        if ($volante === null) {
            throw ValidationException::withMessages(['cantidad' => 'No existe el puesto «Gestor Volante» en el catálogo de puestos.']);
        }

        return $volante;
    }

    private function compartidoCon(int $puestoId): ?int
    {
        foreach ($this->puestos->compartidos($puestoId) as $id) {
            if ($id !== $puestoId) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return array{puesto_id: int|null, autorizadas: int, ocupadas: int, vacantes: int, personas: int}
     */
    private function par(Sucursal $sucursal, ?int $puestoId): array
    {
        if ($puestoId === null) {
            return ['puesto_id' => null, 'autorizadas' => 0, 'ocupadas' => 0, 'vacantes' => 0, 'personas' => 0];
        }

        $plantilla = $this->headcount->plantillaDePar($sucursal->id, $puestoId);

        return [
            'puesto_id' => $puestoId,
            'autorizadas' => $plantilla['autorizada'],
            'ocupadas' => min($plantilla['ocupada'], $plantilla['autorizada']),
            'vacantes' => $plantilla['faltantes'],
            // Titulares de ese puesto (sin repartir): «2 volantes, 1 en plaza de Gestor».
            'personas' => Colaborador::query()
                ->where('sucursal_principal_id', $sucursal->id)
                ->where('puesto_id', $puestoId)
                ->whereIn('estatus', EstadoUsuario::valoresVigentes())
                ->count(),
        ];
    }
}
