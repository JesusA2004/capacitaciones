<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Enviar descriptivo de puesto» (web, RH) después de aprobar la
 * inducción. La regla (permiso, alcance, inducción aprobada, descriptivo
 * oficial vigente) vive en AcuseDescriptivoPuestoService, el mismo que usa
 * la API.
 */
class AcuseDescriptivoPuestoController extends Controller
{
    public function __construct(private readonly AcuseDescriptivoPuestoService $acuses) {}

    public function enviar(Request $request, Colaborador $colaborador): RedirectResponse
    {
        $acuse = $this->acuses->enviar($colaborador, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $acuse->wasRecentlyCreated
                ? sprintf('Descriptivo de puesto enviado a %s: le llegó el aviso en la app.', $colaborador->nombreCompleto())
                : sprintf('Le recordamos a %s que lea su descriptivo de puesto.', $colaborador->nombreCompleto()),
        ]);
    }
}
