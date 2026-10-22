<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AcuseDescriptivoPuesto;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi descriptivo de puesto» en Mi espacio (web): el que RH le envió, con la
 * casilla obligatoria «Enterado(a)…» y el campo «Estoy de acuerdo con…».
 * Siempre el propio (usuario autenticado); misma regla que la app
 * (Api\V1\DescriptivoPuestoColaboradorController) en
 * AcuseDescriptivoPuestoService.
 */
class DescriptivoPuestoController extends Controller
{
    public function __construct(private readonly AcuseDescriptivoPuestoService $acuses) {}

    public function show(Request $request): Response
    {
        $acuse = $this->acuses->vigenteDe($request->user());

        if ($acuse !== null) {
            $acuse = $this->acuses->marcarLeido($acuse, $request->user());
        }

        return Inertia::render('Portal/DescriptivoPuesto', [
            'acuse' => $acuse !== null ? $this->acuses->aArray($acuse, true) : null,
        ]);
    }

    public function acusar(Request $request, AcuseDescriptivoPuesto $acuse): RedirectResponse
    {
        $datos = $request->validate([
            'enterado' => ['nullable', 'boolean'],
            'conformidad' => ['nullable', 'string', 'max:'.AcuseDescriptivoPuestoService::COMENTARIO_MAX],
        ]);

        $this->acuses->acusar($acuse, $request->user(), (bool) ($datos['enterado'] ?? false), '', (string) ($datos['conformidad'] ?? ''));

        return to_route('portal.descriptivo-puesto')->with('toast', ['type' => 'success', 'message' => '¡Listo! Recursos Humanos ya sabe que estás enterado(a) de tu descriptivo de puesto.']);
    }
}
