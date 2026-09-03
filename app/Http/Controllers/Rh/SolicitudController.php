<?php

namespace App\Http\Controllers\Rh;

use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ComentarioSolicitudInternaRequest;
use App\Http\Requests\Rh\RechazarSolicitudInternaRequest;
use App\Models\Departamento;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SolicitudController extends Controller
{
    private const FILTROS = ['estado', 'tipo', 'sucursal_id', 'empresa_id', 'departamento_id', 'puesto_id', 'revisado_por', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(private readonly SolicitudesService $solicitudes) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SolicitudInterna::class);

        return Inertia::render('Rh/Solicitudes/Index', [
            'solicitudes' => $this->solicitudes->paraRevision($request->user(), $request->only(self::FILTROS)),
            'filtros' => $request->only(self::FILTROS),
            'tipos' => $this->solicitudes->tiposDisponibles(),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
                'responsables' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
            ],
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', SolicitudInterna::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Solicitudes internas', $columnas, $filas),
            'solicitudes-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', SolicitudInterna::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Solicitudes internas', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('solicitudes-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $solicitudes = $this->solicitudes->paraExportar($request->user(), $request->only(self::FILTROS));

        $columnas = ['Folio', 'Colaborador', 'Tipo', 'Estado', 'Motivo', 'Revisado por', 'Fecha de creación'];

        $filas = $solicitudes->map(fn (SolicitudInterna $s) => [
            $s->folio,
            $s->usuario ? trim("{$s->usuario->name} {$s->usuario->apellidos}") : null,
            $s->tipo->etiqueta(),
            $s->estado->etiqueta(),
            $s->motivo,
            $s->revisadoPor ? trim("{$s->revisadoPor->name} {$s->revisadoPor->apellidos}") : null,
            $s->created_at->toDateString(),
        ])->all();

        return [$columnas, $filas];
    }

    public function show(Request $request, SolicitudInterna $solicitud): Response
    {
        $this->authorize('view', $solicitud);

        $solicitud->load([
            'usuario:id,name,apellidos,puesto_id,sucursal_principal_id',
            'usuario.puesto:id,nombre',
            'usuario.sucursalPrincipal:id,nombre',
            'revisadoPor:id,name,apellidos',
            'documentos.subidoPor:id,name,apellidos',
            'documentosGenerados.plantilla:id,nombre,tipo',
            'documentosGenerados.generadoPor:id,name,apellidos',
            'historial.usuario:id,name,apellidos',
        ]);

        $puedeGenerarFormato = $request->user()->can('plantillas.generar');

        return Inertia::render('Rh/Solicitudes/Show', [
            'solicitud' => $solicitud,
            'puedeGenerarFormato' => $puedeGenerarFormato,
            'plantillasSugeridas' => $puedeGenerarFormato
                ? DocumentTemplate::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo'])
                : [],
            'tiposDocumentoExpediente' => $puedeGenerarFormato
                ? DocumentType::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                : [],
        ]);
    }

    public function revisar(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('revisar', $solicitud);

        $this->solicitudes->marcarEnRevision($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud marcada en revisión.']);
    }

    public function requerirCorreccion(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('revisar', $solicitud);

        $this->solicitudes->requerirCorreccion($solicitud, $request->user(), (string) $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Se pidió corrección al colaborador.']);
    }

    public function aprobar(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('aprobar', $solicitud);

        $this->solicitudes->aprobar($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud aprobada.']);
    }

    public function rechazar(RechazarSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('rechazar', $solicitud);

        $this->solicitudes->rechazar($solicitud, $request->user(), $request->validated('motivo_rechazo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud rechazada.']);
    }

    public function cerrar(ComentarioSolicitudInternaRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('cerrar', $solicitud);

        $this->solicitudes->cerrar($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud cerrada.']);
    }
}
