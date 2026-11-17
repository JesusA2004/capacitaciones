<?php

namespace App\Http\Controllers\Sistemas;

use App\Http\Controllers\Controller;
use App\Models\AvisoBajaSistemas;
use App\Models\User;
use App\Services\Notificaciones\AvisoBajaSistemasService;
use App\Services\Sistemas\PendientesBajaSistemasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja «Pendientes de baja en sistemas» (Sistemas; RH consulta). Delgado:
 * reglas y autorización en PendientesBajaSistemasService / AvisoBajaSistemasService.
 */
class PendientesBajaController extends Controller
{
    public function __construct(
        private readonly PendientesBajaSistemasService $pendientes,
        private readonly AvisoBajaSistemasService $avisos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $this->usuario($request);
        $this->authorize('viewAny', AvisoBajaSistemas::class);

        $filtros = [
            'filtro' => $request->string('filtro')->toString() ?: 'pendientes',
            'buscar' => $request->string('buscar')->toString(),
        ];

        return Inertia::render('Sistemas/PendientesBaja', [
            'pendientes' => $this->pendientes->listar($usuario, $filtros),
            'totales' => $this->pendientes->totales($usuario),
            'filtros' => $filtros,
            'correoActivo' => $this->avisos->activo(),
        ]);
    }

    /** Detalle (botón «Ver detalle de la baja» del correo). */
    public function show(Request $request, AvisoBajaSistemas $aviso): Response
    {
        return Inertia::render('Sistemas/PendienteBajaDetalle', [
            'pendiente' => $this->pendientes->detalle($aviso, $this->usuario($request)),
        ]);
    }

    public function atender(Request $request, AvisoBajaSistemas $aviso): RedirectResponse
    {
        $datos = $request->validate([
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [], ['notas' => 'notas']);

        $this->pendientes->marcarAtendido($aviso, $this->usuario($request), $datos['notas'] ?? null);

        return back()->with('success', 'Listo: quedó marcado como atendido en sistemas.');
    }

    public function reabrir(Request $request, AvisoBajaSistemas $aviso): RedirectResponse
    {
        $this->pendientes->reabrir($aviso, $this->usuario($request));

        return back()->with('success', 'El pendiente volvió a la lista de pendientes.');
    }

    public function reenviar(Request $request, AvisoBajaSistemas $aviso): RedirectResponse
    {
        $this->avisos->reenviarAviso($aviso, $this->usuario($request));

        return back()->with('success', 'Correo a Sistemas en cola.');
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();

        if (! $usuario instanceof User) {
            abort(403);
        }

        return $usuario;
    }
}
