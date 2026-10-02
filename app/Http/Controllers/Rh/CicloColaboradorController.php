<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
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
    ) {}

    public function show(Request $request, Colaborador $colaborador): Response
    {
        $usuario = $request->user();
        $enCadena = $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $colaborador);

        abort_unless($this->alcance->alcanzaColaborador($usuario, $colaborador) || $enCadena, 403);

        return Inertia::render('Rh/Colaboradores/Ciclo', $this->ciclo->ficha($colaborador, $usuario));
    }
}
