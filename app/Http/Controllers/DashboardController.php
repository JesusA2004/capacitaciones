<?php

namespace App\Http\Controllers;

use App\Exports\RotacionPersonalExport;
use App\Models\Departamento;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Reportes\MetricasRhDashboardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MetricasRhDashboardService $metricas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        if ($usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver')) {
            $vista = $usuario->can('dashboard.global.ver') ? 'Dashboard/Global' : 'Dashboard/Sucursal';

            return Inertia::render($vista, [
                ...$this->metricas->global($usuario),
                'rotacion' => $this->metricas->rotacion($usuario),
                'sucursalesFiltro' => $this->sucursalesVisibles($usuario),
                'departamentosFiltro' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            ]);
        }

        abort_unless($usuario->can('portal.ver'), 403, 'Tu cuenta no tiene un modo de acceso configurado (ni operativo ni colaborador). Contacta a un administrador.');

        return Inertia::render('Dashboard/Colaborador', $this->metricas->colaborador($usuario));
    }

    /**
     * Endpoint JSON para que los filtros de rotación (sucursal, rango de
     * fechas) se actualicen en vivo sin recargar la página — ver
     * resources/js/components/Dashboard/RotacionPersonal.vue.
     */
    public function rotacion(Request $request): JsonResponse
    {
        $usuario = $request->user();

        abort_unless($usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver'), 403);

        return response()->json(
            $this->metricas->rotacion($usuario, $request->only(['sucursal_id', 'departamento_id', 'desde', 'hasta'])),
        );
    }

    public function rotacionExcel(Request $request): BinaryFileResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver'), 403);

        $datos = $this->metricas->rotacion($usuario, $request->only(['sucursal_id', 'departamento_id', 'desde', 'hasta']));

        return Excel::download(new RotacionPersonalExport($datos), 'rotacion-personal-'.now()->format('Y-m-d-His').'.xlsx');
    }

    public function rotacionPdf(Request $request): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver'), 403);

        $datos = $this->metricas->rotacion($usuario, $request->only(['sucursal_id', 'departamento_id', 'desde', 'hasta']));

        $pdf = Pdf::loadView('pdf.rotacion-personal', ['datos' => $datos])->setPaper('letter', 'landscape');

        return $pdf->download('rotacion-personal-'.now()->format('Y-m-d-His').'.pdf');
    }

    /**
     * @return array<int, array{id: int, nombre: string}>
     */
    private function sucursalesVisibles(User $usuario): array
    {
        return Sucursal::query()
            ->when(
                ! $this->alcance->tieneAlcanceGlobal($usuario),
                fn ($q) => $q->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario)),
            )
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Sucursal $s) => ['id' => $s->id, 'nombre' => $s->nombre])
            ->all();
    }
}
