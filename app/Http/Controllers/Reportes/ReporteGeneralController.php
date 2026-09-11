<?php

namespace App\Http\Controllers\Reportes;

use App\Exports\ReporteGeneralExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reportes\MetricasRhDashboardService;
use App\Services\Reportes\ReportesRhService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hub de reportes en tiempo real de /reportes: cruza en una sola vista los
 * módulos de RH que sí están activos hoy (capacitación vive detrás del
 * feature flag `capacitacion`, ver routes/reportes.php y
 * config/features.php — por eso este controlador NO usa
 * MetricasDashboardService). Reutiliza exactamente
 * MetricasRhDashboardService::global(), la misma fuente que ya alimenta
 * Dashboard/Global.vue, para que lo que se ve aquí sea consistente con el
 * resto del portal, y suma un renglón de "otros módulos" (reclutamiento,
 * vacaciones, solicitudes) tomado de ReportesRhService::generar() — mismos
 * catálogos que ya usa /rh/reportes — sin inventar consultas nuevas.
 *
 * index()/exportarExcel()/exportarPdf() piden exactamente los mismos datos,
 * así que lo que exportas es siempre lo que estás viendo en pantalla.
 */
class ReporteGeneralController extends Controller
{
    private const CLAVES_OTROS_MODULOS = [
        'vacantes_abiertas' => 'Vacantes abiertas',
        'candidatos_viables' => 'Candidatos viables',
        'vacaciones_solicitudes' => 'Vacaciones solicitadas',
        'solicitudes_pendientes' => 'Solicitudes pendientes',
    ];

    public function __construct(
        private readonly MetricasRhDashboardService $metricas,
        private readonly ReportesRhService $reportesRh,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.ver'), 403);

        return Inertia::render('Reportes/Index', [
            'metricas' => $this->metricas->global($usuario),
            'otrosModulos' => $this->otrosModulos($usuario),
            'puedeExportar' => $usuario->can('reportes_rh.exportar'),
            'generadoEn' => now()->toIso8601String(),
        ]);
    }

    public function exportarExcel(Request $request): BinaryFileResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.exportar'), 403);

        return Excel::download(
            new ReporteGeneralExport($this->metricas->global($usuario), $this->otrosModulos($usuario)),
            'reporte-general-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.exportar'), 403);

        $pdf = Pdf::loadView('pdf.reporte-general', [
            'metricas' => $this->metricas->global($usuario),
            'otrosModulos' => $this->otrosModulos($usuario),
        ])->setPaper('letter', 'landscape');

        return $pdf->download('reporte-general-'.now()->format('Y-m-d-His').'.pdf');
    }

    /**
     * @return array<int, array{etiqueta: string, valor: int}>
     */
    private function otrosModulos(User $usuario): array
    {
        return array_map(
            fn (string $clave, string $etiqueta) => [
                'etiqueta' => $etiqueta,
                'valor' => count($this->reportesRh->generar($clave, $usuario, [])['filas']),
            ],
            array_keys(self::CLAVES_OTROS_MODULOS),
            array_values(self::CLAVES_OTROS_MODULOS),
        );
    }
}
