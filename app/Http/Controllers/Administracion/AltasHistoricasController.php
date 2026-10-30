<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\ImportacionExcel;
use App\Services\Reportes\AltasHistoricasImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Configuración → Importar datos → «Altas históricas». Toda la regla vive en
 * AltasHistoricasImportService.
 */
class AltasHistoricasController extends Controller
{
    public function __construct(private readonly AltasHistoricasImportService $importador) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('reportes_rh.importar_historico'), 403);

        $seleccionada = $request->integer('importacion');
        $base = ImportacionExcel::query()->where('tipo', AltasHistoricasImportService::TIPO);

        return Inertia::render('Administracion/ImportarDatos/AltasHistoricas', [
            'importacion' => $seleccionada > 0 ? (clone $base)->whereKey($seleccionada)->first()?->only([
                'id', 'nombre_archivo', 'estado', 'total_filas', 'filas_aplicadas', 'filas_revision', 'filas_sin_cambios', 'filas_error', 'detalle', 'metadatos',
            ]) : null,
            'recientes' => (clone $base)->with('aplicadoPor:id,name,apellidos')->latest('id')->limit(10)->get()
                ->map(fn (ImportacionExcel $i): array => [
                    'id' => $i->id,
                    'nombre_archivo' => $i->nombre_archivo,
                    'estado' => $i->estado,
                    'filas_aplicadas' => $i->filas_aplicadas,
                    'aplicado_por' => $i->aplicadoPor?->name,
                ]),
        ]);
    }

    public function plantilla(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('reportes_rh.importar_historico'), 403);

        $libro = $this->importador->plantillaExcel();

        return response()->streamDownload(fn () => (new Xlsx($libro))->save('php://output'), 'formato-altas-historicas.xlsx');
    }

    public function analizar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('reportes_rh.importar_historico'), 403);

        $datos = $request->validate(
            ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']],
            ['archivo.required' => 'Selecciona el archivo de Excel.', 'archivo.mimes' => 'El archivo debe ser .xlsx.', 'archivo.max' => 'El archivo no debe pasar de 10 MB.'],
        );

        $importacion = $this->importador->analizar($datos['archivo'], $request->user());

        return to_route('administracion.importar-datos.altas-historicas.index', ['importacion' => $importacion->id]);
    }

    public function aplicar(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('reportes_rh.importar_historico'), 403);

        $datos = $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:500']],
            ['motivo.required' => 'Indica de dónde salen estas altas.', 'motivo.min' => 'Describe de dónde salen estas altas.'],
        );

        $aplicadas = $this->importador->aplicar($importacion, $request->user(), $datos['motivo']);

        return to_route('administracion.importar-datos.altas-historicas.index', ['importacion' => $importacion->id])
            ->with('toast', ['type' => 'success', 'message' => sprintf('%d alta(s) histórica(s) registradas. Las fechas de ingreso no cambiaron.', $aplicadas)]);
    }
}
