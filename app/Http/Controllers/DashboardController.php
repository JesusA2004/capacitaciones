<?php

namespace App\Http\Controllers;

use App\Exports\RotacionPersonalExport;
use App\Models\Sucursal;
use App\Services\Navigation\NavigationService;
use App\Services\Reportes\MetricasRhDashboardService;
use App\Services\Reportes\TableroRhService;
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
        private readonly NavigationService $navegacion,
        private readonly TableroRhService $tablero,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $modo = $this->navegacion->modoActual($usuario, NavigationService::cookieDe($request));

        abort_if($modo === '', 403, 'Tu cuenta no tiene un modo de acceso configurado (ni operativo ni colaborador). Contacta a un administrador.');

        // "Inicio" respeta el modo elegido en el selector: una cuenta
        // operativa + colaborador que está en "Mi espacio" ve su inicio
        // personal, no el tablero de RH (ver NavigationService).
        if ($modo === 'operativo') {
            $vista = $usuario->can('dashboard.global.ver') ? 'Dashboard/Global' : 'Dashboard/Sucursal';

            // Inicio operativo = SOLO el tablero de RH definido por el negocio
            // (8 indicadores, embudo por hito máximo, tiempo de contratación
            // por nivel y rotación mensual), con filtros de mes y sucursal
            // acotados al alcance. Nada más en la pantalla.
            return Inertia::render($vista, [
                'tablero' => $this->tablero->construir($usuario, [
                    'mes' => $request->string('tablero_mes')->toString() ?: null,
                    'sucursal_id' => $request->integer('tablero_sucursal_id') ?: null,
                ]),
            ]);
        }

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
}
