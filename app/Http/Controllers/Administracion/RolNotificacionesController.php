<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\UpdateRolNotificacionesRequest;
use App\Services\Notificaciones\PoliticaNotificacionRolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Roles y permisos → Notificaciones: qué acción le avisa a qué rol.
 */
class RolNotificacionesController extends Controller
{
    public function __construct(private readonly PoliticaNotificacionRolService $politica) {}

    public function update(UpdateRolNotificacionesRequest $request, Role $rol): RedirectResponse
    {
        /** @var list<string> $eventos */
        $eventos = array_values(array_map('strval', (array) $request->validated('eventos')));
        $this->politica->guardar($rol, $eventos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Avisos de «%s» guardados.', $rol->name)]);
    }

    public function restablecer(Request $request, Role $rol): RedirectResponse
    {
        $this->authorize('editarNotificaciones', $rol);
        $this->politica->restablecer($rol, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Avisos de «%s» restablecidos a los valores por defecto.', $rol->name)]);
    }
}
