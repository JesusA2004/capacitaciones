<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\ImportacionExcel;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PlazasAutorizadasImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Configuración → Importar datos → «Plazas autorizadas». Solo super_admin
 * (headcount.importar). Toda la regla vive en PlazasAutorizadasImportService.
 */
class PlazasAutorizadasController extends Controller
{
    public function __construct(
        private readonly PlazasAutorizadasImportService $importador,
        private readonly HeadcountService $headcount,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('headcount.importar'), 403);

        $seleccionada = $request->integer('importacion');
        $base = ImportacionExcel::query()->where('tipo', PlazasAutorizadasImportService::TIPO);

        return Inertia::render('Administracion/ImportarDatos/PlazasAutorizadas', [
            'poblacion' => $this->headcount->poblacion(),
            'importacion' => $seleccionada > 0 ? (clone $base)->whereKey($seleccionada)->first()?->only([
                'id', 'nombre_archivo', 'estado', 'total_filas', 'filas_aplicadas', 'filas_revision', 'filas_sin_cambios', 'filas_error', 'detalle', 'metadatos',
            ]) : null,
            'recientes' => (clone $base)->with(['subidoPor:id,name,apellidos', 'aplicadoPor:id,name,apellidos'])->latest('id')->limit(10)->get()
                ->map(fn (ImportacionExcel $i): array => [
                    'id' => $i->id,
                    'nombre_archivo' => $i->nombre_archivo,
                    'estado' => $i->estado,
                    'subido_por' => $i->subidoPor->name,
                    'aplicado_por' => $i->aplicadoPor?->name,
                ]),
        ]);
    }

    public function plantilla(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('headcount.importar'), 403);

        $libro = $this->importador->plantillaExcel();

        return response()->streamDownload(fn () => (new Xlsx($libro))->save('php://output'), 'formato-plazas-autorizadas.xlsx');
    }

    public function analizar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('headcount.importar'), 403);

        $datos = $request->validate(
            ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']],
            ['archivo.required' => 'Selecciona el archivo de Excel.', 'archivo.mimes' => 'El archivo debe ser .xlsx.', 'archivo.max' => 'El archivo no debe pasar de 10 MB.'],
        );

        $importacion = $this->importador->analizar($datos['archivo'], $request->user());

        return to_route('administracion.importar-datos.plazas-autorizadas.index', ['importacion' => $importacion->id])
            ->with('toast', ['type' => 'success', 'message' => 'Vista previa lista. Nada se ha guardado todavía; revisa y confirma.']);
    }

    public function aplicar(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('headcount.importar'), 403);

        $datos = $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:400']],
            ['motivo.required' => 'Indica quién autorizó estas plazas o el motivo del cambio.', 'motivo.min' => 'Describe un poco más el motivo.'],
        );

        $resultado = $this->importador->aplicar($importacion, $datos['motivo'], $request->user());

        return to_route('administracion.importar-datos.plazas-autorizadas.index', ['importacion' => $importacion->id])
            ->with('toast', ['type' => 'success', 'message' => sprintf('Plazas aplicadas: %d nuevas y %d actualizadas.', $resultado['creadas'], $resultado['actualizadas'])]);
    }
}
