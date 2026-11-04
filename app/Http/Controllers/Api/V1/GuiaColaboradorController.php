<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Colaboradores\GuiaSiguientePasoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Tu siguiente paso» de la app: pendientes ordenados del propio
 * colaborador (datos → foto → documentos → inducción → firmas) y el estado
 * del modo completar expediente. Toda la regla vive en
 * GuiaSiguientePasoService / ExpedienteIncompletoService.
 */
class GuiaColaboradorController extends Controller
{
    public function __construct(private readonly GuiaSiguientePasoService $guia) {}

    public function show(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403, 'Tu cuenta no está ligada a un colaborador.');

        return response()->json(['data' => $this->guia->guia($usuario, $colaborador)]);
    }
}
