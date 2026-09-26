<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Vacante;
use App\Services\Vacantes\VacantesListadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vacantes para RH desde la app móvil: mismo alcance organizacional y
 * mismo permiso que el panel web (App\Http\Controllers\Rh\VacanteController),
 * solo lectura — gestionar una vacante (crear/editar/cubrir) se queda en
 * el panel web por ahora. Ver seccion 12 del encargo movil.
 */
class VacanteController extends Controller
{
    public function __construct(private readonly VacantesListadoService $vacantes) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('vacantes.ver'), 403);

        // Misma consulta que la web (alcance, filtros y "activas por
        // defecto"): App\Services\Vacantes\VacantesListadoService.
        $query = $this->vacantes->consulta($usuario, [
            'estado' => $request->string('estado')->toString() ?: null,
            'sucursal_id' => $request->integer('sucursal_id') ?: null,
            'busqueda' => $request->string('busqueda')->toString() ?: null,
        ]);

        $vacantes = $query->reorder()->orderByDesc('fecha_apertura')->paginate((int) $request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => collect($vacantes->items())->map(fn (Vacante $v) => [
                'id' => $v->id,
                'puesto' => $v->puesto?->nombre,
                'departamento' => $v->departamento?->nombre,
                'sucursal' => $v->sucursal?->nombre,
                'motivo' => $v->motivo->etiqueta(),
                'estado' => $v->estado->value,
                'estado_etiqueta' => $v->estado->etiqueta(),
                'fecha_apertura' => $v->fecha_apertura->toDateString(),
                'plazas_requeridas' => $v->plazas_requeridas,
                'plazas_cubiertas' => $v->plazas_cubiertas,
                'plazas_disponibles' => $v->plazas_disponibles,
                'generada_automaticamente' => $v->generada_automaticamente,
                'candidatos_count' => $v->candidatos_count,
            ])->values(),
            'meta' => [
                'current_page' => $vacantes->currentPage(),
                'per_page' => $vacantes->perPage(),
                'total' => $vacantes->total(),
            ],
        ]);
    }
}
