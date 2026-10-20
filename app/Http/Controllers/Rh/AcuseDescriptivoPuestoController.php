<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\OnboardingProceso;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Enviar descriptivo de puesto» (web, RH) después de aprobar la
 * inducción. La regla (permiso, alcance, inducción aprobada, descriptivo
 * oficial vigente) vive en AcuseDescriptivoPuestoService, el mismo que usa
 * la API.
 */
class AcuseDescriptivoPuestoController extends Controller
{
    public function __construct(
        private readonly AcuseDescriptivoPuestoService $acuses,
        private readonly OnboardingService $onboarding,
    ) {}

    /**
     * «Inducción al puesto» (índice web, grupo «3 · Onboarding»): por cada
     * colaborador en onboarding, el estado del acuse de su descriptivo de
     * puesto (enviado / visto / aceptado) y si se puede enviar o reenviar.
     * Reutiliza OnboardingService (población en alcance) y
     * AcuseDescriptivoPuestoService (estado del acuse); el envío sigue siendo
     * la acción enviar() de abajo.
     */
    public function index(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can(AcuseDescriptivoPuestoService::PERMISO), 403);

        $listado = $this->onboarding
            ->panoramaInduccionPuesto($usuario, $request->only(['busqueda', 'per_page']))
            ->withQueryString();

        $colaboradores = $listado->getCollection()->map(fn (OnboardingProceso $p): Colaborador => $p->colaborador);
        $resumenes = $this->acuses->resumenesRh($colaboradores, $usuario);

        $listado->getCollection()->transform(function (OnboardingProceso $p) use ($resumenes): array {
            $colaborador = $p->colaborador;
            $resumen = $resumenes[$colaborador->id] ?? ['acuse' => null, 'puede_enviar' => false, 'motivo' => null, 'vista_previa' => null];

            return [
                'colaborador' => [
                    'id' => $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'numero_empleado' => $colaborador->numero_empleado,
                    'puesto' => $colaborador->puesto?->nombre,
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                ],
                'onboarding_estado' => $p->estado->value,
                'onboarding_estado_etiqueta' => $p->estado->etiqueta(),
                'acuse' => $resumen['acuse'],
                'puede_enviar' => $resumen['puede_enviar'],
                'motivo' => $resumen['motivo'],
                'vista_previa' => $resumen['vista_previa'],
            ];
        });

        return Inertia::render('Rh/Onboarding/InduccionPuesto', [
            'colaboradores' => $listado,
            'filtros' => ['busqueda' => (string) $request->query('busqueda', '')],
        ]);
    }

    /**
     * «Ver lo que recibirá»: PDF oficial del descriptivo vigente (inline),
     * antes de enviarlo. No registra nada.
     */
    public function vistaPrevia(Request $request, Colaborador $colaborador): StreamedResponse
    {
        return $this->acuses->pdfVistaPrevia($colaborador, $request->user());
    }

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
