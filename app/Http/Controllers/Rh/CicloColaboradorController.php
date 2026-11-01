<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Flujos\SiguientePasoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha del colaborador en su ciclo laboral (Contratación → Onboarding →
 * Periodo de prueba → Activo → Cierre). Todo el estado viene de
 * CicloLaboralService::ficha() (la misma que usa la API móvil); aquí solo se
 * autoriza y se arma la vista.
 */
class CicloColaboradorController extends Controller
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly SiguientePasoService $siguientePaso,
    ) {}

    public function show(Request $request, Colaborador $colaborador): Response
    {
        $usuario = $request->user();
        $enCadena = $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $colaborador);

        abort_unless($this->alcance->alcanzaColaborador($usuario, $colaborador) || $enCadena, 403);

        $ficha = $this->ciclo->ficha($colaborador, $usuario);

        return Inertia::render('Rh/Colaboradores/Ciclo', [
            ...$ficha,
            'siguientePaso' => $this->siguientePaso->paraColaborador($colaborador, $usuario, $ficha['ciclo'])->toArray(),
        ]);
    }

    /**
     * Misma regla y mismo servicio que POST api/v1/rh/colaboradores/{id}/activar:
     * el servicio rechaza si falta expediente, firma de contratos u onboarding.
     */
    public function activar(Request $request, Colaborador $colaborador, AltaColaboradorService $altas): RedirectResponse
    {
        $this->authorize('activar', $colaborador);
        $altas->activar($colaborador, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador activado.']);
    }
}
