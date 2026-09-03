<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoSolicitudVacaciones;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\RechazarSolicitudVacacionesRequest;
use App\Models\Empresa;
use App\Models\SolicitudVacaciones;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class VacacionesController extends Controller
{
    private const FILTROS = ['estado', 'empresa_id', 'sucursal_id', 'revisado_por', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SolicitudVacaciones::class);

        $solicitudes = $this->queryFiltrada($request)->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Rh/Vacaciones/Index', [
            'solicitudes' => $solicitudes,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'responsables' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
            ],
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', SolicitudVacaciones::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Vacaciones', $columnas, $filas),
            'vacaciones-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', SolicitudVacaciones::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Vacaciones', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('vacaciones-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $solicitudes = $this->queryFiltrada($request)->orderByDesc('created_at')->get();

        $columnas = ['Colaborador', 'Fecha inicio', 'Fecha fin', 'Días', 'Estado', 'Revisado por'];

        $filas = $solicitudes->map(fn (SolicitudVacaciones $s) => [
            $s->usuario ? trim("{$s->usuario->name} {$s->usuario->apellidos}") : null,
            $s->fecha_inicio->toDateString(),
            $s->fecha_fin->toDateString(),
            $s->dias_solicitados,
            $s->estado->etiqueta(),
            $s->revisadoPor ? trim("{$s->revisadoPor->name} {$s->revisadoPor->apellidos}") : null,
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<SolicitudVacaciones>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();
        $idsPermitidos = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->pluck('id');

        return SolicitudVacaciones::query()
            ->with(['usuario:id,name,apellidos,sucursal_principal_id', 'revisadoPor:id,name,apellidos'])
            ->when(
                ! $this->alcance->tieneAlcanceGlobal($usuario),
                fn ($query) => $query->whereIn('user_id', $idsPermitidos),
            )
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->whereHas('usuario.sucursalPrincipal', fn ($q) => $q->where('empresa_id', $valor)))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->whereHas('usuario', fn ($q) => $q->where('sucursal_principal_id', $valor)))
            ->when($request->integer('revisado_por'), fn ($query, $valor) => $query->where('revisado_por', $valor))
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_inicio', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_fin', '<=', $valor))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->whereHas('usuario', function ($sub) use ($busqueda) {
                    $sub->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%");
                });
            });
    }

    public function aprobar(Request $request, SolicitudVacaciones $solicitud): RedirectResponse
    {
        $this->authorize('aprobar', $solicitud);

        $solicitud->update([
            'estado' => EstadoSolicitudVacaciones::Aprobada,
            'revisado_por' => $request->user()?->id,
            'revisado_en' => now(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud de vacaciones aprobada.']);
    }

    public function rechazar(RechazarSolicitudVacacionesRequest $request, SolicitudVacaciones $solicitud): RedirectResponse
    {
        $solicitud->update([
            'estado' => EstadoSolicitudVacaciones::Rechazada,
            'motivo_rechazo' => $request->validated('motivo_rechazo'),
            'revisado_por' => $request->user()?->id,
            'revisado_en' => now(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud de vacaciones rechazada.']);
    }
}
