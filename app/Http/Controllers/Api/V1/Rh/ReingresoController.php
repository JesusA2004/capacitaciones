<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\TipoContratacion;
use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Reingreso;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\ReingresoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reingresos en la app: busca a la MISMA persona, consulta su historial,
 * solicita y (RH) decide. Mismo ReingresoService que Rh\ReingresoController.
 */
class ReingresoController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly ReingresoService $reingresos,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->paginado(
            $this->reingresos->listar($request->user(), $filtros),
            fn (Reingreso $r) => $this->reingresos->aArray($r, $request->user()),
        );
    }

    public function buscar(Request $request): JsonResponse
    {
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->reingresos->buscar((string) ($datos['q'] ?? ''), $request->user())]);
    }

    public function historial(Request $request, int $colaborador): JsonResponse
    {
        $persona = Colaborador::withTrashed()->where('id', $colaborador)->firstOrFail();
        abort_unless($this->alcance->tieneAlcanceGlobal($request->user()) || $this->alcance->alcanzaColaborador($request->user(), $persona), 403);

        return response()->json(['data' => $this->reingresos->historial($persona, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:2000'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'jefe_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'tipo_contratacion' => ['nullable', 'string', 'in:'.implode(',', [...array_column(TipoContratacion::seleccionables(), 'value'), TipoContratacion::PeriodoPrueba->value])],
            'sueldo_mensual' => ['nullable', 'numeric', 'min:0'],
            'fecha_reingreso' => ['nullable', 'date'],
            'documentos_adicionales' => ['nullable', 'array'],
            'documentos_adicionales.*' => ['integer', 'exists:document_types,id'],
        ]);

        $persona = Colaborador::withTrashed()->where('id', (int) $datos['colaborador_id'])->firstOrFail();
        unset($datos['colaborador_id']);

        $reingreso = $this->reingresos->solicitar($persona, $datos, $request->user());

        return response()->json(['data' => $this->reingresos->aArray($reingreso, $request->user())], 201);
    }

    public function decidir(Request $request, Reingreso $reingreso): JsonResponse
    {
        $datos = $request->validate([
            'viable' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);

        $reingreso = $this->reingresos->decidir($reingreso, $request->user(), (bool) $datos['viable'], $datos['comentario'] ?? null);

        return response()->json(['data' => $this->reingresos->aArray($reingreso, $request->user())]);
    }
}
