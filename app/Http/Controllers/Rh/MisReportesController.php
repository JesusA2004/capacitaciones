<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\ReporteGuardado;
use App\Models\User;
use App\Services\Pdf\PdfRendererException;
use App\Services\Reportes\Propios\CatalogoCamposReporte;
use App\Services\Reportes\Propios\ReportePropioService;
use App\Services\Reportes\ReporteExcelService;
use App\Services\Reportes\ReportePdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reportes → Mis reportes. La regla vive en ReportePropioService; aquí solo se
 * verifica el permiso de entrada y la propiedad del reporte guardado.
 */
class MisReportesController extends Controller
{
    public function __construct(
        private readonly ReportePropioService $reportes,
        private readonly CatalogoCamposReporte $catalogo,
        private readonly ReportePdfService $pdf,
        private readonly ReporteExcelService $excel,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $usuario = $this->autorizado($request);
        $seleccion = $request->integer('reporte');
        $reporte = $seleccion > 0 ? $this->guardados($usuario)->whereKey($seleccion)->first() : null;

        return Inertia::render('Rh/Reportes/MisReportes', [
            'fuentes' => $this->catalogo->paraUsuario($usuario),
            'guardados' => $this->guardados($usuario)->orderBy('nombre')->get(['id', 'nombre', 'fuente', 'updated_at']),
            'reporte' => $reporte?->only(['id', 'nombre', 'fuente', 'definicion']),
            'disenoInicial' => $this->reportes->disenoPorDefecto(),
            'colores' => collect((array) config('reportes.colores', []))->map(fn ($c, $clave): array => ['clave' => (string) $clave, 'etiqueta' => (string) ($c['etiqueta'] ?? $clave), 'hex' => (string) ($c['hex'] ?? '')])->values(),
            'puedeExportar' => $usuario->can('reportes_rh.exportar'),
        ]);
    }

    public function vistaPrevia(Request $request): JsonResponse
    {
        $usuario = $this->autorizado($request);
        $resultado = $this->reportes->ejecutar($this->reportes->normalizar($this->definicion($request), $usuario), $usuario);

        return response()->json([
            ...$resultado,
            'filas' => array_slice($resultado['filas'], 0, ReportePropioService::LIMITE_VISTA_PREVIA),
            'limite' => ReportePropioService::LIMITE_VISTA_PREVIA,
        ]);
    }

    /**
     * El PDF real, sin guardar nada, con lo que está capturado en pantalla.
     */
    public function vistaPreviaPdf(Request $request): Response
    {
        $usuario = $this->autorizado($request);

        return $this->respuestaPdf($this->reportes->normalizar($this->definicion($request), $usuario), $usuario, 'inline');
    }

    public function exportar(Request $request): Response
    {
        $usuario = $this->autorizado($request);
        abort_unless($usuario->can('reportes_rh.exportar'), 403);
        $formato = $request->input('formato') === 'pdf' ? 'pdf' : 'excel';
        $definicion = $this->reportes->normalizar($this->definicion($request), $usuario);

        return $formato === 'pdf' ? $this->respuestaPdf($definicion, $usuario, 'attachment') : $this->respuestaExcel($definicion, $usuario);
    }

    public function ejecutar(Request $request, ReporteGuardado $reporte, string $formato): Response
    {
        $usuario = $this->autorizado($request);
        abort_unless($reporte->user_id === $usuario->id && $usuario->can('reportes_rh.exportar'), 403);

        return $formato === 'pdf' ? $this->respuestaPdf($reporte->definicion, $usuario, 'attachment') : $this->respuestaExcel($reporte->definicion, $usuario);
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $this->autorizado($request);
        $reporte = $this->reportes->guardar($usuario, $this->nombre($request), $this->definicion($request));

        return to_route('rh.reportes.mis-reportes.index', ['reporte' => $reporte->id])->with('toast', ['type' => 'success', 'message' => 'Reporte guardado en Mis reportes.']);
    }

    public function update(Request $request, ReporteGuardado $reporte): RedirectResponse
    {
        $usuario = $this->autorizado($request);
        abort_unless($reporte->user_id === $usuario->id, 403);
        $this->reportes->guardar($usuario, $this->nombre($request), $this->definicion($request), $reporte);

        return to_route('rh.reportes.mis-reportes.index', ['reporte' => $reporte->id])->with('toast', ['type' => 'success', 'message' => 'Reporte actualizado.']);
    }

    public function duplicar(Request $request, ReporteGuardado $reporte): RedirectResponse
    {
        $usuario = $this->autorizado($request);
        abort_unless($reporte->user_id === $usuario->id, 403);
        $copia = $this->reportes->duplicar($reporte);

        return to_route('rh.reportes.mis-reportes.index', ['reporte' => $copia->id])->with('toast', ['type' => 'success', 'message' => 'Reporte duplicado.']);
    }

    public function destroy(Request $request, ReporteGuardado $reporte): RedirectResponse
    {
        $usuario = $this->autorizado($request);
        abort_unless($reporte->user_id === $usuario->id, 403);
        $reporte->delete();

        return to_route('rh.reportes.mis-reportes.index')->with('toast', ['type' => 'success', 'message' => 'Reporte eliminado.']);
    }

    /**
     * @param  array<string, mixed>  $definicion
     */
    private function respuestaPdf(array $definicion, User $usuario, string $disposicion): Response
    {
        $resultado = $this->reportes->ejecutar($definicion, $usuario);

        try {
            $pdf = $this->pdf->dePropio($resultado);
        } catch (PdfRendererException $e) {
            Log::warning('Mis reportes: no se pudo generar el PDF.', ['error' => $e->getMessage(), 'causa' => $e->getPrevious()?->getMessage()]);

            return response()->json(['message' => sprintf('No se pudo generar el PDF: %s', $e->getMessage())], 503);
        }

        if ($disposicion === 'attachment') {
            $this->registrarExportacion($usuario, $resultado['fuente'], $resultado['columnas'], 'pdf', $resultado['total']);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s.pdf"', $disposicion, Str::slug($resultado['titulo'])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $definicion
     */
    private function respuestaExcel(array $definicion, User $usuario): Response
    {
        $resultado = $this->reportes->ejecutar($definicion, $usuario);
        $libro = $this->excel->generar($resultado);
        $this->registrarExportacion($usuario, $resultado['fuente'], $resultado['columnas'], 'excel', $resultado['total']);

        return response()->streamDownload(
            fn () => $this->excel->escritor($libro)->save('php://output'),
            sprintf('%s.xlsx', Str::slug($resultado['titulo'])),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Bitácora de qué se exportó: nombres de columnas y conteo, nunca valores.
     *
     * @param  list<array{clave: string, etiqueta: string, tipo: string}>  $columnas
     */
    private function registrarExportacion(User $usuario, string $fuente, array $columnas, string $formato, int $total): void
    {
        activity('reportes')->causedBy($usuario)
            ->withProperties(['fuente' => $fuente, 'formato' => $formato, 'columnas' => array_column($columnas, 'clave'), 'registros' => $total])
            ->log('Exportó un reporte de Mis reportes');
    }

    private function autorizado(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();
        abort_unless($usuario->can('reportes_rh.ver') && $usuario->can('reportes_rh.mis_reportes'), 403);

        return $usuario;
    }

    /**
     * @return Builder<ReporteGuardado>
     */
    private function guardados(User $usuario): Builder
    {
        return ReporteGuardado::query()->where('user_id', $usuario->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function definicion(Request $request): array
    {
        $datos = $request->validate(['definicion' => ['required', 'array']], ['definicion.required' => 'Configura el reporte primero.']);

        return $datos['definicion'];
    }

    private function nombre(Request $request): string
    {
        return $request->validate(
            ['nombre' => ['required', 'string', 'max:120']],
            ['nombre.required' => 'Ponle nombre al reporte.', 'nombre.max' => 'El nombre admite hasta 120 caracteres.'],
        )['nombre'];
    }
}
