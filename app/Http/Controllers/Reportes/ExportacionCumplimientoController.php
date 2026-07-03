<?php

namespace App\Http\Controllers\Reportes;

use App\Exports\CumplimientoExport;
use App\Http\Controllers\Controller;
use App\Services\Reportes\ReporteCumplimientoService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportacionCumplimientoController extends Controller
{
    public function exportar(Request $request, ReporteCumplimientoService $service): BinaryFileResponse
    {
        $usuario = $request->user();

        if (! $usuario->can('reportes.exportar')) {
            abort(403);
        }

        $filtros = $request->only(['sucursal_id', 'departamento_id', 'curso_id']);

        return Excel::download(
            new CumplimientoExport($service, $usuario, $filtros),
            'reporte-cumplimiento-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
