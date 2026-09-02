<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoSolicitudVacaciones;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\RechazarSolicitudVacacionesRequest;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VacacionesController extends Controller
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SolicitudVacaciones::class);

        $usuario = $request->user();
        $idsPermitidos = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->pluck('id');

        $solicitudes = SolicitudVacaciones::query()
            ->with('usuario:id,name,apellidos,sucursal_principal_id')
            ->when(
                ! $this->alcance->tieneAlcanceGlobal($usuario),
                fn ($query) => $query->whereIn('user_id', $idsPermitidos),
            )
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Rh/Vacaciones/Index', [
            'solicitudes' => $solicitudes,
            'filtros' => $request->only('estado'),
        ]);
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
