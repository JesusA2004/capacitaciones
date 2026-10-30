<?php

namespace App\Http\Controllers\Reportes;

use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Models\Baja;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\TableroRotacionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Tablero de rotación (Reportes → Rotación de personal): Resumen,
 * Tendencia 12 meses, Causales, Sucursales y Puestos, con exportación a
 * Excel y PDF respetando los filtros. Combina el histórico del Excel de
 * rotación con las bajas del sistema (TableroRotacionService). La carga del
 * Excel histórico sigue en reportes.rotacion.index.
 */
class TableroRotacionController extends Controller
{
    public function __construct(
        private readonly TableroRotacionService $tablero,
        private readonly BajaConsultaService $bajas,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('verTablero', Baja::class);
        $usuario = $request->user();

        return Inertia::render('Reportes/TableroRotacion', [
            'pestanas' => $this->bajas->pestanas($usuario),
            'tablero' => $this->tablero->tablero($usuario, $request->query()),
            'puedeCargarExcel' => $usuario->can('rotacion.cargar'),
            'puedeVerHistorico' => $usuario->can('rotacion.ver'),
        ]);
    }

    public function excel(Request $request): HttpResponse
    {
        $this->authorize('verTablero', Baja::class);
        $tabla = $this->tablero->exportable($request->user(), $request->query());

        return Excel::download(new ReporteRhExport($tabla['titulo'], $tabla['columnas'], $tabla['filas']), sprintf('rotacion-%s.xlsx', now()->format('Y-m-d')));
    }

    public function pdf(Request $request): HttpResponse
    {
        $this->authorize('verTablero', Baja::class);
        $tabla = $this->tablero->exportable($request->user(), $request->query());

        return Pdf::loadView('pdf.reporte-rh', $tabla)
            ->setPaper('letter', 'landscape')
            ->download(sprintf('rotacion-%s.pdf', now()->format('Y-m-d')));
    }
}
