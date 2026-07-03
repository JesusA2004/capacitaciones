<?php

namespace App\Exports;

use App\Models\User;
use App\Services\Reportes\ReporteCumplimientoService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Misma consulta que la pantalla de reporte (ReporteCumplimientoService), sin
 * paginar: reutiliza porColaborador() y simplemente pide todas las filas
 * (Excel no necesita paginacion server-side). El aislamiento por sucursal se
 * hereda automaticamente porque el servicio ya aplica
 * AlcanceOrganizacionalService antes de que este export exista.
 *
 * @implements WithMapping<User>
 */
class CumplimientoExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, curso_id?: int|string|null}  $filtros
     */
    public function __construct(
        private readonly ReporteCumplimientoService $service,
        private readonly User $usuarioActual,
        private readonly array $filtros,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function collection(): Collection
    {
        return $this->service->todosLosColaboradores($this->usuarioActual, $this->filtros);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Colaborador', 'Sucursal', 'Departamento', 'Asignadas', 'Completadas', 'Vencidas', 'Cumplimiento (%)'];
    }

    /**
     * @param  User  $usuario
     * @return array<int, string|int>
     */
    public function map($usuario): array
    {
        $total = (int) $usuario->getAttribute('asignaciones_total');
        $completadas = (int) $usuario->getAttribute('asignaciones_completadas');
        $vencidas = (int) $usuario->getAttribute('asignaciones_vencidas');

        return [
            trim("{$usuario->name} {$usuario->apellidos}"),
            $usuario->sucursalPrincipal->nombre ?? '—',
            $usuario->departamento->nombre ?? '—',
            $total,
            $completadas,
            $vencidas,
            $total > 0 ? (int) round(($completadas / $total) * 100) : 0,
        ];
    }
}
