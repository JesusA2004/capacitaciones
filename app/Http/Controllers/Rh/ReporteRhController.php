<?php

namespace App\Http\Controllers\Rh;

use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\ReporteGuardado;
use App\Models\Sucursal;
use App\Services\Pdf\PdfRendererException;
use App\Services\Reportes\AltasNoiService;
use App\Services\Reportes\ReportePdfService;
use App\Services\Reportes\ReportesRhService;
use App\Support\Export\ChartData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReporteRhController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'colaborador_id', 'fecha_inicio', 'fecha_fin', 'estado', 'tipo_solicitud', 'tipo_documento', 'mes'];

    public function __construct(
        private readonly ReportesRhService $reportes,
        private readonly ReportePdfService $pdf,
        private readonly AltasNoiService $noi,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.ver'), 403);

        $clave = $request->string('reporte')->toString() ?: 'empleados_total';
        $filtros = $request->only(self::FILTROS);
        $resultado = $this->reportes->generar($clave, $usuario, $filtros);
        $grafica = ($resultado['sin_grafica'] ?? false) ? null : ChartData::fromTable($resultado['columnas'], $resultado['filas']);

        return Inertia::render('Rh/Reportes/Index', [
            'catalogo' => $this->reportes->catalogo(),
            'reporte' => $clave,
            'filtros' => $filtros,
            'resultado' => $resultado,
            'grafica' => $grafica === null ? null : [
                'tipo' => $grafica->tipo,
                'categorias' => $grafica->categorias,
                'series' => $grafica->series,
                'recortado' => $grafica->recortado,
            ],
            'puedeExportar' => $usuario->can('reportes_rh.exportar'),
            'puede' => [
                'mis_reportes' => $usuario->can('reportes_rh.mis_reportes'),
                'noi_configurar' => $usuario->can('reportes_rh.datos_sensibles'),
                'noi_exportar' => $usuario->can('reportes_rh.exportar') && $usuario->can('reportes_rh.datos_sensibles') && $usuario->can('salarios.ver'),
            ],
            'guardados' => $usuario->can('reportes_rh.mis_reportes')
                ? ReporteGuardado::query()->where('user_id', $usuario->id)->orderBy('nombre')->get(['id', 'nombre'])
                : [],
            'noi' => $clave === 'altas_noi' && $usuario->can('reportes_rh.datos_sensibles') ? [
                'layout' => $this->noi->layout(),
                'campos' => collect($this->noi->campos())->map(fn (array $c, string $campo): array => ['clave' => $campo, 'etiqueta' => $c['etiqueta']])->values(),
            ] : null,
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
            new ReporteRhExport($resultado['titulo'], $resultado['columnas'], $resultado['filas'], ! ($resultado['sin_grafica'] ?? false)),
            Str::slug($resultado['titulo']).'-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.exportar'), 403);

        $clave = $request->string('reporte')->toString() ?: 'empleados_total';
        $resultado = $this->reportes->generar($clave, $usuario, $request->only(self::FILTROS));

        try {
            $pdf = $this->pdf->deCatalogo($resultado);
        } catch (PdfRendererException $e) {
            Log::warning('Reportes de RH: no se pudo generar el PDF.', ['reporte' => $clave, 'error' => $e->getMessage(), 'causa' => $e->getPrevious()?->getMessage()]);

            return back()->with('toast', ['type' => 'error', 'message' => sprintf('No se pudo generar el PDF: %s', $e->getMessage())]);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s-%s.pdf"', Str::slug($resultado['titulo']), now()->format('Y-m-d')),
        ]);
    }

    /**
     * Layout configurable de Altas para NOI: exige datos sensibles y salarios,
     * para no entregar un archivo con datos enmascarados que parezca correcto.
     */
    public function exportarNoi(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.exportar') && $usuario->can('reportes_rh.datos_sensibles') && $usuario->can('salarios.ver'), 403);

        $libro = $this->noi->exportar($usuario, $request->only(self::FILTROS));
        $csv = $this->noi->layout()['formato'] === 'csv';
        $nombre = sprintf('altas-noi-pendiente-de-homologar-%s.%s', now()->format('Y-m-d'), $csv ? 'csv' : 'xlsx');
        $escritor = $csv ? new Csv($libro) : new Xlsx($libro);

        return response()->streamDownload(fn () => $escritor->save('php://output'), $nombre, [
            'Content-Type' => $csv ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function guardarLayoutNoi(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.datos_sensibles'), 403);

        $datos = $request->validate([
            'columnas' => ['required', 'array', 'min:1', 'max:40'],
            'columnas.*.campo' => ['required', 'string'],
            'columnas.*.encabezado' => ['nullable', 'string', 'max:80'],
            'formato' => ['required', 'in:xlsx,csv'],
        ], ['columnas.required' => 'El layout necesita al menos una columna.']);

        $this->noi->guardarLayout($usuario, $datos['columnas'], $datos['formato']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Layout de Altas para NOI actualizado.']);
    }
}
