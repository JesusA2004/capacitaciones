<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\AltaColaboradorRequest;
use App\Http\Requests\CicloLaboral\ContratarCandidatoRequest;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta de colaboradores (manual RH o candidato contratado), estado del alta
 * con checklist, activación y jerarquía. Ver AltaColaboradorService.
 */
class AltaColaboradorController extends Controller
{
    public function __construct(
        private readonly AltaColaboradorService $altas,
        private readonly ContratacionCandidatoService $contratacion,
        private readonly JerarquiaColaboradorService $jerarquia,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function store(AltaColaboradorRequest $request): JsonResponse
    {
        $this->exigirSucursalEnAlcance($request, (int) $request->validated('sucursal_principal_id'));

        $colaborador = $this->altas->registrar($request->validated(), $request->user());

        return response()->json(['data' => $this->altas->checklist($colaborador, $request->user()), 'colaborador_id' => $colaborador->id], 201);
    }

    public function contratarCandidato(ContratarCandidatoRequest $request, Candidato $candidato): JsonResponse
    {
        $sucursal = $request->validated('sucursal_principal_id') ?? $candidato->sucursal_id;

        if ($sucursal !== null) {
            $this->exigirSucursalEnAlcance($request, (int) $sucursal);
        }

        // Mismo Service que la web (CandidatoController::iniciarContratacion).
        // Idempotente: si el candidato ya está en contratación con un QR
        // vigente, devuelve esa misma invitación (200) en vez de otra.
        $resultado = $this->contratacion->iniciarOReanudar($candidato, $request->validated(), $request->user());

        return response()->json([
            'data' => $this->altas->checklist($resultado['colaborador'], $request->user()),
            'colaborador_id' => $resultado['colaborador']->id,
            'invitacion' => $this->contratacion->datosQr($resultado['invitacion'], $resultado['token']),
            'reutilizada' => $resultado['reutilizada'],
        ], $resultado['reutilizada'] ? 200 : 201);
    }

    public function show(Colaborador $colaborador): JsonResponse
    {
        $this->authorize('verAlta', $colaborador);

        return response()->json(['data' => $this->altas->checklist($colaborador, $request->user())]);
    }

    public function activar(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('activar', $colaborador);

        $colaborador = $this->altas->activar($colaborador, $request->user());

        return response()->json(['data' => $this->altas->checklist($colaborador, $request->user())]);
    }

    public function jerarquia(Colaborador $colaborador): JsonResponse
    {
        $this->authorize('verJerarquia', $colaborador);

        return response()->json(['data' => $this->jerarquia->jerarquia($colaborador)]);
    }

    /**
     * Un RH con alcance por sucursal no puede dar de alta en otra sucursal
     * (evita crear personal fuera de su alcance manipulando el id).
     */
    private function exigirSucursalEnAlcance(Request $request, int $sucursalId): void
    {
        $usuario = $request->user();

        abort_unless(
            $this->alcance->tieneAlcanceGlobal($usuario) || $this->alcance->sucursalesVisiblesIds($usuario)->contains($sucursalId),
            403,
            'La sucursal está fuera de tu alcance.',
        );
    }
}
