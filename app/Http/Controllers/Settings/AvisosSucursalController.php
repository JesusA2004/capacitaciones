<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvisosSucursalUpdateRequest;
use App\Services\Notificaciones\SucursalesSilenciadasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Avisos por sucursal: cada persona con alcance amplio
 * (permiso notificaciones.silenciar_sucursales) elige de qué sucursales no
 * quiere avisos. La regla vive en SucursalesSilenciadasService.
 */
class AvisosSucursalController extends Controller
{
    public function __construct(private readonly SucursalesSilenciadasService $silenciadas) {}

    public function edit(Request $request): Response
    {
        abort_unless($this->silenciadas->puedeConfigurar($request->user()), 403, 'Tu rol no permite elegir de qué sucursales recibir avisos.');

        return Inertia::render('settings/AvisosSucursal', [
            'sucursales' => $this->silenciadas->opciones($request->user()),
        ]);
    }

    public function update(AvisosSucursalUpdateRequest $request): RedirectResponse
    {
        $ids = array_values(array_map('intval', (array) $request->validated('sucursales', [])));
        $this->silenciadas->guardar($request->user(), $ids);

        return back()->with('toast', ['type' => 'success', 'message' => $ids === [] ? 'Recibirás avisos de todas las sucursales.' : 'Preferencia de avisos guardada.']);
    }
}
