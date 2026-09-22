<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use App\Services\Headcount\HeadcountService;
use App\Services\Reportes\IndicadoresRhService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plantilla autorizada vs. activa, vacantes, indicadores y organigrama de
 * personas. Todo calculado en backend y acotado por alcance.
 */
class EstructuraController extends Controller
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly IndicadoresRhService $indicadores,
        private readonly JerarquiaColaboradorService $jerarquia,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function cobertura(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('headcount.ver'), 403);

        $sucursales = $this->alcance->tieneAlcanceGlobal($request->user()) ? null : $this->alcance->sucursalesVisiblesIds($request->user())->map(fn ($id) => (int) $id);

        if ($request->filled('sucursal_id')) {
            $sucursal = $request->integer('sucursal_id');
            $sucursales = collect($sucursales === null || $sucursales->contains($sucursal) ? [$sucursal] : []);
        }

        return response()->json(['data' => $this->headcount->coberturaDetallada($sucursales, $request->filled('empresa_id') ? $request->integer('empresa_id') : null)]);
    }

    public function indicadores(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('indicadores.ver'), 403);

        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'sucursal_id' => ['nullable', 'integer'],
            'empresa_id' => ['nullable', 'integer'],
            'dias_vencimiento' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json(['data' => $this->indicadores->calcular($request->user(), $filtros)]);
    }

    public function organigrama(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('organigrama.ver'), 403);

        $visibles = $this->alcance->tieneAlcanceGlobal($request->user()) ? null : $this->alcance->sucursalesVisiblesIds($request->user())->map(fn ($id) => (int) $id);

        return response()->json(['data' => $this->jerarquia->organigrama(
            $request->filled('sucursal_id') ? $request->integer('sucursal_id') : null,
            $request->filled('departamento_id') ? $request->integer('departamento_id') : null,
            $visibles,
        )]);
    }

    public function vacante(Request $request, Vacante $vacante): JsonResponse
    {
        abort_unless($request->user()->can('vacantes.ver'), 403);
        abort_unless(
            $this->alcance->tieneAlcanceGlobal($request->user())
            || ($vacante->sucursal_id !== null && $this->alcance->sucursalesVisiblesIds($request->user())->contains($vacante->sucursal_id)),
            403,
        );

        $vacante->load(['empresa:id,nombre', 'sucursal:id,nombre', 'puesto:id,nombre', 'candidatoContratado:id,nombre,apellidos', 'colaboradorContratado:id,name,apellidos,numero_empleado']);

        return response()->json(['data' => [
            'id' => $vacante->id,
            'empresa' => $vacante->empresa?->nombre,
            'sucursal' => $vacante->sucursal?->nombre,
            'puesto' => $vacante->puesto?->nombre,
            'motivo' => $vacante->motivo->value,
            'estado' => $vacante->estado->value,
            'responsable_rh_id' => $vacante->responsable_rh_id,
            'fecha_apertura' => $vacante->fecha_apertura->toDateString(),
            'fecha_cierre' => $vacante->fecha_cierre?->toDateString(),
            'dias_abierta' => $vacante->diasAbierta(),
            'plazas_requeridas' => $vacante->plazas_requeridas,
            'plazas_cubiertas' => $vacante->plazas_cubiertas,
            'plazas_disponibles' => $vacante->plazas_disponibles,
            'candidato_contratado' => $vacante->candidatoContratado?->nombreCompleto(),
            'colaborador_contratado' => $vacante->colaboradorContratado !== null ? [
                'id' => $vacante->colaboradorContratado->id,
                'nombre' => $vacante->colaboradorContratado->nombreCompleto(),
                'numero_empleado' => $vacante->colaboradorContratado->numero_empleado,
            ] : null,
        ]]);
    }
}
