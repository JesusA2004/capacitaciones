<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Directorio\DirectorioColaboradoresService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio de la empresa para la app móvil: mismo service y misma regla
 * que la web (global para cualquier colaborador autenticado, solo contacto
 * corporativo — ver DirectorioColaboradoresService).
 */
class DirectorioController extends Controller
{
    public function __construct(private readonly DirectorioColaboradoresService $directorio) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->only(['busqueda', 'puesto_id', 'sucursal_id', 'departamento_id', 'solo_con_correo']);
        $paginador = $this->directorio->buscar($filtros, $request->integer('per_page', 30));

        return response()->json([
            'data' => array_map(fn (Colaborador $c) => $this->directorio->aArray($c), $paginador->items()),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'last_page' => $paginador->lastPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
            ],
        ]);
    }

    public function opciones(): JsonResponse
    {
        return response()->json(['data' => $this->directorio->opciones()]);
    }
}
