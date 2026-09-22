<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Services\Contratos\ContratoLaboralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContratoController extends Controller
{
    public function __construct(private readonly ContratoLaboralService $contratos) {}

    /**
     * Contratos vigentes que vencen en los próximos N días (default 30).
     */
    public function porVencer(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('contratos.ver'), 403);

        $dias = max(1, min(365, $request->integer('dias', 30)));

        return response()->json([
            'data' => $this->contratos->porVencer($request->user(), $dias)->map(fn (ContratoLaboral $c) => $this->contratos->aArray($c))->values(),
            'dias' => $dias,
        ]);
    }

    public function delColaborador(Colaborador $colaborador): JsonResponse
    {
        $this->authorize('verContratos', $colaborador);

        return response()->json([
            'data' => $colaborador->contratos()->with(['colaborador', 'evaluacion', 'documento'])->get()->map(fn (ContratoLaboral $c) => $this->contratos->aArray($c))->values(),
        ]);
    }
}
