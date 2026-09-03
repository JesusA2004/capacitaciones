<?php

namespace App\Http\Controllers\Rh;

use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Reportes\ReportesRhService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReporteRhController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'colaborador_id', 'fecha_inicio', 'fecha_fin', 'estado', 'tipo_solicitud', 'tipo_documento'];

    public function __construct(private readonly ReportesRhService $reportes) {}

    public function index(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.ver'), 403);

        $clave = $request->string('reporte')->toString() ?: 'empleados_total';
        $filtros = $request->only(self::FILTROS);

        return Inertia::render('Rh/Reportes/Index', [
            'catalogo' => $this->reportes->catalogo(),
            'reporte' => $clave,
            'filtros' => $filtros,
            'resultado' => $this->reportes->generar($clave, $usuario, $filtros),
            'puedeExportar' => $usuario->can('reportes_rh.exportar'),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
        ]);
    }

    public function exportarExcel(Request $request): BinaryFileResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.exportar'), 403);

        $clave = $request->string('reporte')->toString() ?: 'empleados_total';
        $resultado = $this->reportes->generar($clave, $usuario, $request->only(self::FILTROS));

        return Excel::download(
            new ReporteRhExport($resultado['titulo'], $resultado['columnas'], $resultado['filas']),
            Str::slug($resultado['titulo']).'-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.exportar'), 403);

        $clave = $request->string('reporte')->toString() ?: 'empleados_total';
        $resultado = $this->reportes->generar($clave, $usuario, $request->only(self::FILTROS));

        $pdf = Pdf::loadView('pdf.reporte-rh', $resultado)->setPaper('letter', 'landscape');

        return $pdf->download(Str::slug($resultado['titulo']).'-'.now()->format('Y-m-d').'.pdf');
    }
}
