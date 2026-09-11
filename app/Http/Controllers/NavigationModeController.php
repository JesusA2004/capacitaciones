<?php

namespace App\Http\Controllers;

use App\Services\Navigation\NavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Selector de experiencia (sección 1C del encargo): un usuario con ambos
 * modos disponibles (ver App\Services\Navigation\NavigationService) puede
 * cambiar entre "Mi espacio" (modo colaborador) y "Operación RH" (modo
 * operativo) desde el sidebar. El modo elegido se guarda en la cookie
 * `NavigationService::nombreCookie()`, no en base de datos — es una
 * preferencia del navegador, no un dato persistente del usuario.
 */
class NavigationModeController extends Controller
{
    public function update(Request $request, NavigationService $navegacion): RedirectResponse
    {
        $datos = $request->validate([
            'modo' => ['required', 'string', 'in:colaborador,operativo'],
        ]);

        $usuario = $request->user();
        $disponibles = $navegacion->modosDisponibles($usuario);

        abort_unless(in_array($datos['modo'], $disponibles, true), 403);

        return redirect($datos['modo'] === 'colaborador' ? route('portal.index') : route('dashboard'))
            ->withCookie(cookie()->forever(NavigationService::nombreCookie(), $datos['modo']));
    }
}
