<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Services\RhMobile\RhPendientesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PendienteController extends Controller
{
    public function __construct(private readonly RhPendientesService $pendientes) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.pendientes.ver'), 403);

        $filtros = $request->only(['tipo', 'q', 'sucursal_id', 'departamento_id', 'prioridad', 'page', 'per_page']);
        $pagina = $this->pendientes->bandeja($usuario, $filtros);
        $conteos = $this->pendientes->resumenConteos($usuario);

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'solicitudes' => $conteos['solicitudes'],
                'vacaciones' => $conteos['vacaciones'],
                'documentos' => $conteos['documentos'],
                'incorporaciones' => $conteos['incorporaciones'],
            ],
        ]);
    }
}
