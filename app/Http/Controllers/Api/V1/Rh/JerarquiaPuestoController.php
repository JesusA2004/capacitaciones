<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Puesto;
use App\Services\Administracion\JerarquiaPuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organigrama de puestos para la app movil (mismo criterio de permiso y la
 * misma consulta que el panel web, ver
 * App\Http\Controllers\Administracion\JerarquiaPuestoController y
 * App\Services\Administracion\JerarquiaPuestoService — no se duplica la
 * logica). Regresa la lista plana con `puesto_superior_id`; el cliente arma
 * el arbol localmente como ya hace resources/js/pages/Administracion/JerarquiaPuestos/Index.vue.
 */
class JerarquiaPuestoController extends Controller
{
    public function __construct(
        private readonly JerarquiaPuestoService $jerarquia,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Puesto::class);

        $puestos = $this->jerarquia->arbol($request)->map(fn (Puesto $puesto) => [
            'id' => $puesto->id,
            'nombre' => $puesto->nombre,
            'departamento' => $puesto->departamento?->nombre,
            'nivel_jerarquico' => $puesto->nivel_jerarquico,
            'puesto_superior_id' => $puesto->puesto_superior_id,
            'puesto_superior' => $puesto->puestoSuperior?->nombre,
            'tipo_puesto' => $puesto->tipo_puesto?->value,
            'activo' => $puesto->activo,
            'usuarios_count' => $puesto->usuarios_count,
            'vacantes_abiertas_count' => $puesto->vacantes_abiertas_count,
        ]);

        return response()->json(['data' => $puestos->values()]);
    }
}
