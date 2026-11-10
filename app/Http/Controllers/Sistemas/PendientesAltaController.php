<?php

namespace App\Http\Controllers\Sistemas;

use App\Http\Controllers\Controller;
use App\Models\AvisoAltaSistemas;
use App\Models\User;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use App\Services\Sistemas\PendientesAltaSistemasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja «Pendientes de alta en sistemas» (Sistemas; RH consulta). Delgado:
 * reglas y autorización en PendientesAltaSistemasService / AvisoAltaSistemasService.
 */
class PendientesAltaController extends Controller
{
    public function __construct(
        private readonly PendientesAltaSistemasService $pendientes,
        private readonly AvisoAltaSistemasService $avisos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $this->usuario($request);
        $this->authorize('viewAny', AvisoAltaSistemas::class);

        $filtros = [
            'filtro' => $request->string('filtro')->toString() ?: 'pendientes',
            'buscar' => $request->string('buscar')->toString(),
        ];

        return Inertia::render('Sistemas/PendientesAlta', [
            'pendientes' => $this->pendientes->listar($usuario, $filtros),
            'totales' => $this->pendientes->totales($usuario),
            'filtros' => $filtros,
            'correoActivo' => $this->avisos->activo(),
            'puedeAtender' => $usuario->can(AvisoAltaSistemasService::PERMISO_ATENDER),
        ]);
    }

    /** Detalle con «Copiar» por dato (botón «Abrir y copiar datos» del correo). */
    public function show(Request $request, AvisoAltaSistemas $aviso): Response
    {
        return Inertia::render('Sistemas/PendienteAltaDetalle', [
            'pendiente' => $this->pendientes->detalle($aviso, $this->usuario($request)),
        ]);
    }

    public function atender(Request $request, AvisoAltaSistemas $aviso): RedirectResponse
    {
        $datos = $request->validate([
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [], ['notas' => 'notas']);

        $this->pendientes->marcarAtendido($aviso, $this->usuario($request), $datos['notas'] ?? null);

        return back()->with('success', 'Listo: quedó marcado como dado de alta en sistemas.');
    }

    public function reabrir(Request $request, AvisoAltaSistemas $aviso): RedirectResponse
    {
        $this->pendientes->reabrir($aviso, $this->usuario($request));

        return back()->with('success', 'El pendiente volvió a la lista de pendientes.');
    }

    public function reenviar(Request $request, AvisoAltaSistemas $aviso): RedirectResponse
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
