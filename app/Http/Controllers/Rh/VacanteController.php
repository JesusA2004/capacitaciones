<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoVacante;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Vacantes\VacantesListadoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Vacantes (docs/HEADCOUNT_Y_VACANTES.md): la lista de las vacantes REALES
 * — qué puesto falta, dónde, cuántas plazas, desde cuándo y con cuántos
 * candidatos —, no la tabla de plantilla por sucursal (esa vive en
 * Administración > Sucursales > detalle). Las vacantes se abren y cierran
 * solas desde headcount (VacanteAutoGenerationService). Toda la regla vive
 * en VacantesListadoService, compartido con la API móvil.
 */
class VacanteController extends Controller
{
    private const FILTROS = ['busqueda', 'sucursal_id', 'puesto_id', 'departamento_id', 'estado'];

    public function __construct(
        private readonly VacantesListadoService $vacantes,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vacante::class);
        $usuario = $request->user();
        $sucursales = $this->alcance->sucursalesVisiblesIds($usuario);

        return Inertia::render('Rh/Vacantes/Index', [
            'vacantes' => $this->vacantes->filas($this->vacantes->consulta($usuario, $this->filtros($request))->get()),
            'kpis' => $this->vacantes->kpis($usuario),
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'sucursales' => Sucursal::query()->whereIn('id', $sucursales)->orderBy('nombre')->get(['id', 'nombre']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
                'estados' => collect(EstadoVacante::cases())->map(fn (EstadoVacante $e) => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()])->values(),
            ],
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Vacante::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Vacantes', $columnas, $filas),
            'vacantes-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Vacante::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Vacantes', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('vacantes-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{busqueda?: string|null, sucursal_id?: int|string|null, puesto_id?: int|string|null, departamento_id?: int|string|null, estado?: string|null}
     */
    private function filtros(Request $request): array
    {
        $datos = $request->validate([
            'busqueda' => ['nullable', 'string', 'max:100'],
            'sucursal_id' => ['nullable', 'integer'],
            'puesto_id' => ['nullable', 'integer'],
            'departamento_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string'],
        ]);

        return [
            'busqueda' => isset($datos['busqueda']) ? (string) $datos['busqueda'] : null,
            'sucursal_id' => isset($datos['sucursal_id']) ? (int) $datos['sucursal_id'] : null,
            'puesto_id' => isset($datos['puesto_id']) ? (int) $datos['puesto_id'] : null,
            'departamento_id' => isset($datos['departamento_id']) ? (int) $datos['departamento_id'] : null,
            'estado' => isset($datos['estado']) ? (string) $datos['estado'] : null,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function tabla(Request $request): array
    {
        $filas = $this->vacantes->filas($this->vacantes->consulta($request->user(), $this->filtros($request))->get());

        $columnas = ['Puesto', 'Sucursal', 'Estado', 'Plazas por cubrir', 'Abierta desde', 'Días abierta', 'Candidatos activos', 'Plantilla autorizada', 'Plantilla actual', 'Origen'];

        $tabla = array_map(fn (array $f) => [
            $f['puesto'],
            $f['sucursal'],
            $f['estado_etiqueta'],
            $f['plazas_disponibles'],
            $f['fecha_apertura'],
            $f['dias_abierta'],
            $f['candidatos_activos'],
            $f['plantilla_autorizada'],
            $f['plantilla_actual'],
            $f['generada_automaticamente'] ? 'Automática (headcount)' : 'Manual',
        ], $filas);

        return [$columnas, $tabla];
    }
}
