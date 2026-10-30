<?php

namespace App\Http\Controllers\Salarios;

use App\Enums\MotorPdf;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Models\ImportacionExcel;
use App\Models\MatrizMerito;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\TabuladorSalarial;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use App\Services\Salarios\MatrizMeritoService;
use App\Services\Salarios\PosicionSalarialService;
use App\Services\Salarios\TabuladorImportService;
use App\Services\Salarios\TabuladorSalarialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organización → Administración de salarios. Cada acción autoriza con salarios.*
 * (no bastan expedientes.* ni ser gerente); toda la regla vive en App\Services\Salarios.
 */
class AdministracionSalariosController extends Controller
{
    public function __construct(
        private readonly TabuladorSalarialService $tabulador,
        private readonly PosicionSalarialService $posiciones,
        private readonly MatrizMeritoService $matriz,
        private readonly TabuladorImportService $importador,
        private readonly AuditoriaService $auditoria,
        private readonly PdfRendererFactory $pdf,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('salarios.ver'), 403);

        $sucursalId = $request->integer('sucursal') ?: null;
        $filas = $this->posiciones->posiciones($request->user(), $sucursalId);
        $renglones = $this->posiciones->renglonesTabulador($filas);
        $puestoId = $request->integer('puesto') ?: $this->puestoInicial($renglones);
        $versiones = $puestoId !== null ? $this->tabulador->versiones($puestoId) : collect();
        $vigente = $versiones->first(fn (TabuladorSalarial $v): bool => $v->vigente_hasta === null);

        $this->auditoria->registrar('salarios.consultado', null, $request->user(), ['puesto_id' => $puestoId, 'sucursal_id' => $sucursalId]);

        $puedeImportar = $request->user()->can('salarios.importar');
        $importacionId = $request->integer('importacion');

        return Inertia::render('Administracion/Salarios/Index', [
            'resumen' => $this->posiciones->resumen($filas),
            'tabulador' => $renglones,
            'puestoId' => $puestoId,
            'rango' => $vigente !== null
                ? ['minimo' => (float) $vigente->minimo, 'medio' => (float) $vigente->medio, 'maximo' => (float) $vigente->maximo]
                : null,
            'colaboradores' => $filas->where('puesto_id', $puestoId)->values(),
            'versiones' => $versiones->map(fn (TabuladorSalarial $v): array => [
                'version' => $v->version,
                'minimo' => (float) $v->minimo,
                'medio' => (float) $v->medio,
                'maximo' => (float) $v->maximo,
                'fuente' => $v->fuente,
                'fecha_actualizacion' => $v->fecha_actualizacion->toDateString(),
                'vigente_desde' => $v->vigente_desde->toDateString(),
                'vigente_hasta' => $v->vigente_hasta?->toDateString(),
                'origen' => $v->origen,
                'usuario' => $v->usuario?->name,
            ])->values(),
            'matriz' => $this->matriz->vigente()?->celdas,
            'historialMatriz' => $this->matriz->historial()->map(fn (MatrizMerito $m): array => [
                'id' => $m->id,
                'vigente_desde' => $m->vigente_desde->toDateString(),
                'vigente_hasta' => $m->vigente_hasta?->toDateString(),
                'motivo' => $m->motivo,
                'usuario' => $m->usuario?->name,
            ])->values(),
            'sucursales' => Sucursal::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'sucursalId' => $sucursalId,
            'puede' => [
                'editar' => $request->user()->can('salarios.editar'),
                'exportar' => $request->user()->can('salarios.exportar'),
                'importar' => $puedeImportar,
            ],
            'importacion' => $puedeImportar && $importacionId > 0
                ? ImportacionExcel::query()->where('tipo', TabuladorImportService::TIPO)->whereKey($importacionId)->first()?->only([
                    'id', 'nombre_archivo', 'estado', 'total_filas', 'filas_aplicadas', 'filas_revision', 'filas_sin_cambios', 'filas_error', 'detalle',
                ])
                : null,
        ]);
    }

    public function guardarTabulador(Request $request, Puesto $puesto): RedirectResponse
    {
        abort_unless($request->user()->can('salarios.editar'), 403);

        $datos = $request->validate([
            'minimo' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'medio' => ['required', 'numeric', 'gte:minimo', 'max:99999999'],
            'maximo' => ['required', 'numeric', 'gte:medio', 'max:99999999'],
            'fuente' => ['required', 'string', 'max:160'],
            'fecha_actualizacion' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:400'],
        ], [
            'minimo.gt' => 'El mínimo debe ser mayor a cero.',
            'medio.gte' => 'El medio no puede ser menor que el mínimo.',
            'maximo.gte' => 'El máximo no puede ser menor que el medio.',
            'fuente.required' => 'Indica la fuente del estudio.',
            'fecha_actualizacion.required' => 'Indica la fecha de actualización.',
            'fecha_actualizacion.before_or_equal' => 'La fecha de actualización no puede ser futura.',
        ]);

        $version = $this->tabulador->guardar($puesto, $datos, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $version === null
                ? 'Sin cambios: se conserva la versión vigente.'
                : sprintf('Tabulador de %s guardado (versión %d). Ningún sueldo cambió.', $puesto->nombre, $version->version),
        ]);
    }

    public function guardarMatriz(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('salarios.editar'), 403);

        $datos = $request->validate([
            'celdas' => ['required', 'array'],
            'motivo' => ['nullable', 'string', 'max:400'],
        ]);

        $this->matriz->guardar($datos['celdas'], $datos['motivo'] ?? null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Matriz guardada. Son sugerencias: no se aplicó ningún aumento.']);
    }

    public function exportarExcel(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('salarios.exportar'), 403);

        $tabla = $this->tablaExportable($request);

        return Excel::download(
            new ReporteRhExport($tabla['titulo'], $tabla['columnas'], $tabla['filas']),
            sprintf('salarios-%s.xlsx', now()->format('Y-m-d')),
        );
    }

    public function exportarPdf(Request $request): HttpResponse|RedirectResponse
    {
        abort_unless($request->user()->can('salarios.exportar'), 403);

        try {
            $resultado = $this->pdf->renderizar(
                MotorPdf::Browsershot,
                view('pdf.reporte-rh', $this->tablaExportable($request))->render(),
                new OpcionesPdf(tamano: 'letter', orientacion: 'landscape', numerarPaginas: true, textoPie: 'MR. LANA PEOPLE · Administración de salarios'),
            );
        } catch (PdfRendererException $e) {
            Log::warning('Salarios: no se pudo generar el PDF.', ['error' => $e->getMessage()]);

            return back()->with('toast', ['type' => 'error', 'message' => 'No se pudo generar el PDF. Intenta de nuevo o descarga el Excel.']);
        }

        return response($resultado['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="salarios-%s.pdf"', now()->format('Y-m-d')),
        ]);
    }

    public function formatoImportacion(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('salarios.importar'), 403);

        $libro = $this->importador->plantillaExcel();

        return response()->streamDownload(fn () => (new Xlsx($libro))->save('php://output'), 'formato-tabulador-salarial.xlsx');
    }

    public function analizarImportacion(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('salarios.importar'), 403);

        $datos = $request->validate(
            ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']],
            ['archivo.required' => 'Selecciona el archivo de Excel.', 'archivo.mimes' => 'El archivo debe ser .xlsx.', 'archivo.max' => 'El archivo no debe pasar de 10 MB.'],
        );

        $importacion = $this->importador->analizar($datos['archivo'], $request->user());

        return to_route('salarios.index', ['importacion' => $importacion->id])
            ->with('toast', ['type' => 'success', 'message' => 'Vista previa lista. Nada se ha guardado todavía.']);
    }

    public function aplicarImportacion(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('salarios.importar'), 403);

        $datos = $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:400']],
            ['motivo.required' => 'Indica el motivo de la carga.', 'motivo.min' => 'Describe un poco más el motivo.'],
        );

        $aplicadas = $this->importador->aplicar($importacion, $datos['motivo'], $request->user());

        return to_route('salarios.index')
            ->with('toast', ['type' => 'success', 'message' => sprintf('%d puesto(s) con versión nueva. Ningún sueldo cambió.', $aplicadas)]);
    }

    /**
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, mixed>>}
     */
    private function tablaExportable(Request $request): array
    {
        $vista = $request->string('vista')->toString() === 'posiciones' ? 'posiciones' : 'tabulador';
        $sucursalId = $request->integer('sucursal') ?: null;
        $puestoId = $request->integer('puesto') ?: null;

        $this->auditoria->registrar('salarios.exportado', null, $request->user(), ['vista' => $vista, 'puesto_id' => $puestoId, 'sucursal_id' => $sucursalId]);

        return $vista === 'posiciones'
            ? $this->posiciones->tablaPosiciones($request->user(), $sucursalId, $puestoId)
            : $this->posiciones->tablaTabulador($request->user());
    }

    /**
     * @param  list<array<string, mixed>>  $renglones
     */
    private function puestoInicial(array $renglones): ?int
    {
        foreach ($renglones as $renglon) {
            if ($renglon['version'] !== null && $renglon['colaboradores'] > 0) {
                return (int) $renglon['puesto_id'];
            }
        }

        return isset($renglones[0]) ? (int) $renglones[0]['puesto_id'] : null;
    }
}
