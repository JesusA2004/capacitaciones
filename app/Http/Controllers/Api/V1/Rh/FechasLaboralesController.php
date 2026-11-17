<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FechasLaboralesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fechas laborales en la API (aditivo; docs/FECHAS_LABORALES.md): mismo
 * FechasLaboralesService que la web. Para editarlas, la API ya expone el
 * dominio `fechas_laborales` en /api/v1/rh/correcciones (mismo
 * CorreccionService: motivo, vista previa e historial).
 */
class FechasLaboralesController extends Controller
{
    public function __construct(
        private readonly FechasLaboralesService $fechas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_if($usuario === null, 401);
        abort_unless($usuario->colaborador_id !== $colaborador->id && $this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        return response()->json(['data' => $this->fechas->resumen($colaborador, $usuario)]);
    }
}
