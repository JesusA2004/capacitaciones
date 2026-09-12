<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PersonalizacionUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personalización visual de la cuenta (tema de color, color de avatar,
 * animaciones) — distinta de settings/Appearance (claro/oscuro, solo
 * localStorage/cookie): esto se guarda en `users.preferencias_ui` para que
 * se recuerde entre dispositivos, igual que cualquier otra preferencia real
 * del colaborador.
 */
class PersonalizacionController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Personalizacion', [
            'preferencias' => $request->user()->preferencias_ui,
        ]);
    }

    public function update(PersonalizacionUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['preferencias_ui' => $request->validated()]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Personalización guardada.']);
    }
}
