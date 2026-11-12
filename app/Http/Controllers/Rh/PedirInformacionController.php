<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\PedirInformacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Pedir información al colaborador» (expediente, ficha del candidato, ciclo
 * laboral): qué le falta y el aviso (PedirInformacionService).
 */
class PedirInformacionController extends Controller
{
    public function __construct(
        private readonly PedirInformacionService $informacion,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->autorizar($request->user(), $colaborador);

        return response()->json(['data' => $this->informacion->resumen($colaborador)]);
    }

    public function pedir(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->autorizar($request->user(), $colaborador);

        $resumen = $this->informacion->pedir($colaborador, $request->user());

        return response()->json([
            'data' => $resumen,
            'message' => sprintf('Se le pidió a %s en la web y en la app (%d pendiente%s).', $colaborador->name, $resumen['total_persona'], $resumen['total_persona'] === 1 ? '' : 's'),
        ]);
    }

    private function autorizar(User $usuario, Colaborador $colaborador): void
    {
        abort_unless(
            ($usuario->can('expedientes.revisar') || $usuario->can('documentos_laborales.generar')) && $this->alcance->puedeVerExpediente($usuario, $colaborador),
            403,
            'No puedes pedirle información a esta persona.',
        );
    }
}
