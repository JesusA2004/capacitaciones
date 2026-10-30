<?php

namespace App\Http\Controllers\Reportes;

use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bajas\GuardarMetaRotacionRequest;
use App\Models\Baja;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Rotacion\RotacionMetaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pestaña Rotación de Bajas: vistas Global, Corporativo, Todas las sucursales,
 * Por sucursal y Por causales, con exportación por vista que respeta los filtros y
 * metas editables por RH. Combina el histórico del Excel con las bajas del sistema
 * (TableroRotacionService). La carga del Excel sigue en reportes.rotacion.index.
 */
class TableroRotacionController extends Controller
{
    public function __construct(
        private readonly TableroRotacionService $tablero,
        private readonly BajaConsultaService $bajas,
        private readonly RotacionMetaService $metas,
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
            'puedeEditarMetas' => $usuario->can('bajas.gestionar'),
        ]);
    }

    public function guardarMeta(GuardarMetaRotacionRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('bajas.gestionar'), 403);
        $this->metas->registrar($request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Meta registrada. Los meses anteriores conservan la que tenían.']);
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
