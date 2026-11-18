<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\TipoAjustePermiso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\GuardarPermisosEspecialesRequest;
use App\Models\User;
use App\Services\Permisos\CatalogoPermisosService;
use App\Services\Permisos\PermisosEspecialesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Permisos especiales» por persona: concede o quita permisos a una cuenta
 * sin tocar su rol. Delgado: las reglas viven en PermisosEspecialesService
 * y en UserPolicy::gestionarPermisosEspeciales().
 */
class PermisosEspecialesController extends Controller
{
    public function __construct(
        private readonly PermisosEspecialesService $servicio,
        private readonly CatalogoPermisosService $catalogo,
    ) {}

    /**
     * Quién tiene permisos especiales hoy (acceso desde «Roles y permisos»).
     */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($this->servicio->puedeAdministrar($actor), 403);

        return Inertia::render('Administracion/PermisosEspeciales/Index', [
            'personas' => $this->servicio->personasConAjustes($actor),
        ]);
    }

    public function show(Request $request, User $usuario): Response
    {
        $this->authorize('verPermisosEspeciales', $usuario);

        return Inertia::render('Administracion/PermisosEspeciales/Show', [
            ...$this->servicio->vista($usuario, $request->user()),
            'catalogo' => $this->catalogo->paraPantalla(),
        ]);
    }

    public function update(GuardarPermisosEspecialesRequest $request, User $usuario): RedirectResponse
    {
        $deseados = [];

        foreach ((array) $request->validated('ajustes', []) as $ajuste) {
            $deseados[(string) $ajuste['permiso']] = TipoAjustePermiso::from((string) $ajuste['tipo']);
        }

        $hasta = $request->validated('hasta');
        $resumen = $this->servicio->guardar(
            $usuario,
            $deseados,
            $request->validated('motivo'),
            is_string($hasta) && $hasta !== '' ? Carbon::parse($hasta)->endOfDay() : null,
            $request->user(),
        );

        $total = $resumen['concedidos'] + $resumen['quitados'] + $resumen['retirados'];

        return back()->with('toast', [
            'type' => 'success',
            'message' => $total === 0
                ? 'Sin cambios: los permisos especiales ya estaban así.'
                : sprintf('Permisos especiales guardados: %d concedido(s), %d quitado(s), %d retirado(s).', $resumen['concedidos'], $resumen['quitados'], $resumen['retirados']),
        ]);
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('gestionarPermisosEspeciales', $usuario);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $retirados = $this->servicio->retirarTodos($usuario, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $retirados === 0
                ? 'No tenía permisos especiales.'
                : sprintf('Se retiraron %d permiso(s) especial(es): queda exactamente con lo de su rol.', $retirados),
        ]);
    }
}
