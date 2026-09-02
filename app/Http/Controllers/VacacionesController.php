<?php

namespace App\Http\Controllers;

use App\Enums\EstadoSolicitudVacaciones;
use App\Http\Requests\Vacaciones\StoreSolicitudVacacionesRequest;
use App\Models\SolicitudVacaciones;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VacacionesController extends Controller
{
    public function __construct(private readonly VacacionesService $vacaciones) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        $solicitudes = SolicitudVacaciones::query()
            ->where('user_id', $usuario->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Vacaciones/Index', [
            'saldo' => $this->vacaciones->saldo($usuario),
            'solicitudes' => $solicitudes,
        ]);
    }

    public function store(StoreSolicitudVacacionesRequest $request): RedirectResponse
    {
        $usuario = $request->user();
        $saldo = $this->vacaciones->saldo($usuario);

        if ($request->validated('dias_solicitados') > $saldo['dias_disponibles']) {
            return back()->withErrors(['dias_solicitados' => 'No tienes suficientes días disponibles.'])->withInput();
        }

        SolicitudVacaciones::create([
            ...$request->validated(),
            'user_id' => $usuario->id,
            'estado' => EstadoSolicitudVacaciones::Pendiente,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud de vacaciones enviada.']);
    }

    public function cancelar(SolicitudVacaciones $solicitud): RedirectResponse
    {
        $this->authorize('cancelar', $solicitud);

        abort_unless($solicitud->estado === EstadoSolicitudVacaciones::Pendiente, 422, 'Solo se puede cancelar una solicitud pendiente.');

        $solicitud->update(['estado' => EstadoSolicitudVacaciones::Cancelada]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud cancelada.']);
    }
}
