<?php

namespace App\Http\Controllers;

use App\Enums\EstadoSolicitudVacaciones;
use App\Http\Requests\Vacaciones\StoreSolicitudVacacionesRequest;
use App\Models\SolicitudVacaciones;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VacacionesController extends Controller
{
    public function __construct(private readonly VacacionesService $vacaciones) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        return Inertia::render('Vacaciones/Index', [
            'saldo' => $this->vacaciones->saldo($usuario),
            'solicitudes' => $this->vacaciones->misSolicitudes($usuario),
        ]);
    }

    public function store(StoreSolicitudVacacionesRequest $request): RedirectResponse
    {
        try {
            $this->vacaciones->solicitar($request->user(), $request->validated());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

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
