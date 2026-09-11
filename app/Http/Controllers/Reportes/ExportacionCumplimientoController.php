<?php

namespace App\Http\Controllers\Reportes;

use App\Exports\CumplimientoExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reportes\ReporteCumplimientoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ExportacionCumplimientoController extends Controller
{
    public function __construct(private readonly ReporteCumplimientoService $service) {}

    public function exportar(Request $request): BinaryFileResponse
    {
        $usuario = $request->user();

        if (! $usuario->can('reportes.exportar')) {
            abort(403);
        }

        $filtros = $request->only(['sucursal_id', 'departamento_id', 'curso_id']);

        return Excel::download(
            new CumplimientoExport($this->service, $usuario, $filtros),
            'reporte-cumplimiento-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): Response
    {
        $usuario = $request->user();

        if (! $usuario->can('reportes.exportar')) {
            abort(403);
        }

        $filtros = $request->only(['sucursal_id', 'departamento_id', 'curso_id']);

        $pdf = Pdf::loadView('pdf.reporte-cumplimiento', [
            'resumen' => $this->service->resumenGeneral($usuario),
            'porSucursal' => $this->service->cumplimientoPorSucursal($usuario)->all(),
            'porDepartamento' => $this->service->cumplimientoPorDepartamento($usuario)->all(),
            'colaboradores' => $this->service->todosLosColaboradores($usuario, $filtros)
                ->map(fn (User $c) => $this->filaColaborador($c))
                ->all(),
        ])->setPaper('letter', 'landscape');

        return $pdf->download('reporte-cumplimiento-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{colaborador: string, sucursal: string, departamento: string, total: int, completadas: int, vencidas: int, porcentaje: int}
     */
    private function filaColaborador(User $usuario): array
    {
        $total = (int) $usuario->getAttribute('asignaciones_total');
        $completadas = (int) $usuario->getAttribute('asignaciones_completadas');

        return [
            'colaborador' => trim("{$usuario->name} {$usuario->apellidos}"),
            'sucursal' => $usuario->sucursalPrincipal->nombre ?? '—',
            'departamento' => $usuario->departamento->nombre ?? '—',
            'total' => $total,
            'completadas' => $completadas,
            'vencidas' => (int) $usuario->getAttribute('asignaciones_vencidas'),
            'porcentaje' => $total > 0 ? (int) round(($completadas / $total) * 100) : 0,
        ];
    }
}
