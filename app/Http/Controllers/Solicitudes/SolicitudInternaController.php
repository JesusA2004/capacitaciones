<?php

namespace App\Http\Controllers\Solicitudes;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudInternaRequest;
use App\Http\Requests\Solicitudes\SubirDocumentoSolicitudRequest;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaDocumento;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vista del colaborador sobre sus propias solicitudes. La revisión de RH/
 * gerencia vive en App\Http\Controllers\Rh\SolicitudController — mismo
 * SolicitudesService, controladores separados (ver sección 4 del encargo).
 */
class SolicitudInternaController extends Controller
{
    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly VacacionesService $vacaciones,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        return Inertia::render('Solicitudes/Index', [
            'solicitudes' => $this->solicitudes->paraColaborador($usuario),
            'tipos' => $this->solicitudes->tiposConFormulario(),
            'saldoVacaciones' => $this->vacaciones->saldo($usuario),
            // Solo quien puede solicitar una baja ve a quién puede
            // seleccionar (su propio alcance organizacional, nunca a todos
            // los colaboradores) — ver TipoSolicitudInterna::BajaColaborador.
            'colaboradoresParaBaja' => $usuario->can('solicitudes.bajas.crear')
                ? $this->alcance->limitarColaboradoresPorAlcance(
                    Colaborador::query()->where('estatus', EstadoUsuario::Activo)->where('id', '!=', $usuario->colaborador_id),
                    $usuario,
                )->orderBy('name')->get(['id', 'name', 'apellidos'])
                : [],
        ]);
    }

    public function store(StoreSolicitudInternaRequest $request): RedirectResponse
    {
        $this->solicitudes->crear($request->user(), $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud enviada. Te avisaremos cuando sea revisada.']);
    }

    public function show(Request $request, SolicitudInterna $solicitud): Response
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['usuario:id,name,apellidos', 'colaboradorObjetivo:id,name,apellidos', 'revisadoPor:id,name,apellidos', 'documentos', 'documentosGenerados.plantilla:id,nombre,tipo', 'officialFormatGenerations.formato:id,nombre', 'historial.usuario:id,name,apellidos']);

        return Inertia::render('Solicitudes/Show', [
            'solicitud' => $solicitud,
        ]);
    }

    public function cancelar(SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('cancelar', $solicitud);

        $this->solicitudes->cancelar($solicitud, request()->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud cancelada.']);
    }

    public function subirDocumento(SubirDocumentoSolicitudRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->solicitudes->adjuntarDocumento($solicitud, $request->file('archivo'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento adjuntado.']);
    }

    public function verDocumento(SolicitudInterna $solicitud, SolicitudInternaDocumento $documento): StreamedResponse
    {
        $this->authorize('view', $solicitud);

        return $this->solicitudes->documento($solicitud, $documento);
    }
}
