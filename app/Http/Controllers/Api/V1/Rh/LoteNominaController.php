<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\PeriodicidadNomina;
use App\Http\Controllers\Controller;
use App\Models\NominaLote;
use App\Services\Nomina\LoteNominaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Lotes de nómina para la app RH: mismo LoteNominaService que la web.
 * Preparar NO publica; solo emitir() publica y avisa.
 */
class LoteNominaController extends Controller
{
    public function __construct(private readonly LoteNominaService $lotes) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.ver'), 403);
        $pagina = $this->lotes->listar($request->user());

        return response()->json([
            'data' => array_map(fn (NominaLote $l) => $this->lotes->resumen($l), $pagina->items()),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $datos = $request->validate([
            'periodicidad' => ['required', Rule::enum(PeriodicidadNomina::class)],
            'fecha' => ['required', 'date'],
        ]);

        $lote = $this->lotes->preparar(PeriodicidadNomina::from($datos['periodicidad']), Carbon::parse($datos['fecha']), $request->user());

        return response()->json(['data' => $this->lotes->resumen($lote)], 201);
    }

    public function show(Request $request, NominaLote $lote): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.ver'), 403);
        $filtro = $request->string('filtro')->toString() ?: 'todos';

        return response()->json(['data' => [
            ...$this->lotes->resumen($lote),
            'recibos' => $this->lotes->recibos($lote, $request->user(), $filtro),
        ]]);
    }

    public function emitir(Request $request, NominaLote $lote): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $resultado = $this->lotes->emitir($lote, $request->user());

        return response()->json(['data' => [...$this->lotes->resumen($resultado['lote']), 'emitidos_ahora' => $resultado['emitidos']]]);
    }

    public function cancelar(Request $request, NominaLote $lote): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $this->lotes->resumen($this->lotes->cancelar($lote, $datos['motivo'], $request->user()))]);
    }
}
